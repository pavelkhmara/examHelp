<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GenerationTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Task management endpoints for manual intervention
 */
class TaskManagementController extends Controller
{
    /**
     * Cancel a specific task
     * POST /api/tasks/{taskId}/cancel
     */
    public function cancelTask(int $taskId, Request $request)
    {
        $task = GenerationTask::query()->findOrFail($taskId);

        $oldStatus = $task->status;

        // Only cancel if task is in active state
        if (!in_array($oldStatus, ['queued', 'running', 'pending_confirmation', 'pending_clarification'])) {
            return response()->json([
                'success' => false,
                'message' => "Cannot cancel task in '{$oldStatus}' status. Only active tasks can be cancelled.",
                'task' => [
                    'id' => $task->id,
                    'status' => $oldStatus,
                ],
            ], 400);
        }

        $reason = $request->input('reason', 'Manually cancelled via Task Management');

        $task->status = 'cancelled';
        $task->error = $reason;

        $task->addActivity(
            'task_cancelled',
            "Task manually cancelled (was: {$oldStatus})",
            ['old_status' => $oldStatus, 'reason' => $reason, 'cancelled_by' => 'api']
        );

        $task->save();

        Log::info('[TaskManagement] Task cancelled', [
            'task_id' => $task->id,
            'old_status' => $oldStatus,
            'reason' => $reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Task cancelled successfully',
            'task' => [
                'id' => $task->id,
                'old_status' => $oldStatus,
                'new_status' => $task->status,
            ],
        ]);
    }

    /**
     * Cancel all active tasks for an exam
     * POST /api/exams/{examId}/tasks/cancel-all
     */
    public function cancelAllExamTasks(string $examId, Request $request)
    {
        $exam = \App\Models\Exam::query()->findOrFail($examId);

        $activeTasks = GenerationTask::query()
            ->where('exam_id', $exam->id)
            ->whereIn('status', ['queued', 'running', 'pending_confirmation', 'pending_clarification'])
            ->get();

        if ($activeTasks->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No active tasks to cancel',
                'cancelled_count' => 0,
            ]);
        }

        $reason = $request->input('reason', 'Bulk cancellation via Task Management');
        $cancelledCount = 0;

        foreach ($activeTasks as $task) {
            $oldStatus = $task->status;

            $task->status = 'cancelled';
            $task->error = $reason;

            $task->addActivity(
                'task_cancelled',
                "Task cancelled in bulk (was: {$oldStatus})",
                ['old_status' => $oldStatus, 'reason' => $reason, 'cancelled_by' => 'api']
            );

            $task->save();
            $cancelledCount++;
        }

        Log::info('[TaskManagement] Bulk task cancellation', [
            'exam_id' => $exam->id,
            'cancelled_count' => $cancelledCount,
            'reason' => $reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Cancelled {$cancelledCount} task(s) successfully",
            'cancelled_count' => $cancelledCount,
            'exam_id' => $exam->id,
        ]);
    }

    /**
     * Retry a failed task
     * POST /api/tasks/{taskId}/retry
     */
    public function retryTask(int $taskId)
    {
        $task = GenerationTask::query()->findOrFail($taskId);

        $oldStatus = $task->status;

        // Only retry failed or cancelled tasks
        if (!in_array($oldStatus, ['failed', 'cancelled'])) {
            return response()->json([
                'success' => false,
                'message' => "Cannot retry task in '{$oldStatus}' status. Only failed/cancelled tasks can be retried.",
                'task' => [
                    'id' => $task->id,
                    'status' => $oldStatus,
                ],
            ], 400);
        }

        $task->status = 'queued';
        $task->error = null;
        $task->attempts = ($task->attempts ?? 0) + 1;

        $task->addActivity(
            'task_retried',
            "Task manually retried (was: {$oldStatus})",
            ['old_status' => $oldStatus, 'attempt' => $task->attempts]
        );

        $task->save();

        // Re-dispatch the job
        $jobClass = $task->request['job_class'] ?? \App\Jobs\RunExamResearchJob::class;
        if (class_exists($jobClass)) {
            dispatch(new $jobClass($task->id));
        }

        Log::info('[TaskManagement] Task retried', [
            'task_id' => $task->id,
            'old_status' => $oldStatus,
            'attempt' => $task->attempts,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Task queued for retry',
            'task' => [
                'id' => $task->id,
                'old_status' => $oldStatus,
                'new_status' => $task->status,
                'attempts' => $task->attempts,
            ],
        ]);
    }

    /**
     * Force complete a stuck task (dangerous!)
     * POST /api/tasks/{taskId}/force-complete
     */
    public function forceCompleteTask(int $taskId, Request $request)
    {
        $task = GenerationTask::query()->findOrFail($taskId);

        $oldStatus = $task->status;

        $task->status = 'completed';
        $task->error = null;

        $note = $request->input('note', 'Force completed via Task Management');

        $task->addActivity(
            'task_force_completed',
            "Task force completed (was: {$oldStatus}) - {$note}",
            ['old_status' => $oldStatus, 'forced_by' => 'api', 'note' => $note]
        );

        $task->save();

        Log::warning('[TaskManagement] Task force completed', [
            'task_id' => $task->id,
            'old_status' => $oldStatus,
            'note' => $note,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Task force completed (⚠️ use with caution)',
            'task' => [
                'id' => $task->id,
                'old_status' => $oldStatus,
                'new_status' => $task->status,
            ],
        ]);
    }

    /**
     * Get stuck tasks (running for more than 1 hour)
     * GET /api/tasks/stuck
     */
    public function getStuckTasks()
    {
        $stuckTasks = GenerationTask::query()
            ->whereIn('status', ['running', 'queued'])
            ->where('updated_at', '<', now()->subHour())
            ->with('exam:id,title')
            ->orderBy('updated_at')
            ->get()
            ->map(function ($task) {
                $stuckDuration = now()->diffInMinutes($task->updated_at);

                return [
                    'id' => $task->id,
                    'exam_id' => $task->exam_id,
                    'exam_title' => $task->exam->title ?? 'Unknown',
                    'type' => $task->type,
                    'status' => $task->status,
                    'stuck_duration_minutes' => $stuckDuration,
                    'last_activity' => $task->updated_at?->toISOString(),
                    'created_at' => $task->created_at?->toISOString(),
                ];
            });

        return response()->json([
            'success' => true,
            'stuck_tasks_count' => $stuckTasks->count(),
            'stuck_tasks' => $stuckTasks,
        ]);
    }

    /**
     * Force start research for an exam (bypasses all checks)
     * POST /api/exams/{examId}/research/force-start
     */
    public function forceStartResearch(string $examId, Request $request)
    {
        try {
            Log::info('[TaskManagement] forceStartResearch called', [
                'exam_id' => $examId,
                'request_data' => $request->all(),
            ]);

            $exam = \App\Models\Exam::query()->findOrFail($examId);

            $validated = $request->validate([
                'without_confirmation' => ['nullable', 'boolean'],
                'overview_model' => ['nullable', 'string', 'in:gpt-5-mini,gpt-5'],
                'cancel_active' => ['nullable', 'boolean'],
            ]);

            Log::info('[TaskManagement] Validation passed', ['validated' => $validated]);

            // Cancel active tasks first if requested
            if ($validated['cancel_active'] ?? true) {
                $cancelled = GenerationTask::query()
                    ->where('exam_id', $exam->id)
                    ->whereIn('status', ['queued', 'running', 'pending_confirmation', 'pending_clarification'])
                    ->update([
                        'status' => 'cancelled',
                        'error' => 'Cancelled via force-start API',
                    ]);

                Log::info('[TaskManagement] Cancelled active tasks before force-start', [
                    'exam_id' => $exam->id,
                    'cancelled_count' => $cancelled,
                ]);
            }

            // Parse user_input from exam
            $userInput = [];
            if (!empty($exam->user_input)) {
                if (is_array($exam->user_input)) {
                    $userInput = $exam->user_input;
                } elseif (is_string($exam->user_input)) {
                    $decoded = json_decode($exam->user_input, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $userInput = $decoded;
                    }
                }
            }

            // Get last document
            $documentId = null;
            $lastDoc = $exam->documents()->latest()->first();
            if ($lastDoc) {
                $documentId = $lastDoc->id;
            }

            // Create task directly in DB
            try {
                $task = GenerationTask::create([
                    'exam_id' => $exam->id,
                    'subject_type' => get_class($exam),
                    'subject_id' => $exam->id,
                    'type' => 'research',
                    'status' => 'queued',
                    'request' => [
                        'exam_id' => $exam->id,
                        'source' => 'force_start_api',
                        'user_input' => $userInput,
                        'document_id' => $documentId,
                        'without_confirmation' => $validated['without_confirmation'] ?? true,
                        'overview_model' => $validated['overview_model'] ?? 'gpt-5-mini',
                        'force_restart' => true,
                    ],
                    'attempts' => 0,
                    'result' => null,
                    'error' => null,
                    'idempotency_key' => "exam:{$exam->id}:research:force_start:" . now()->timestamp . ':' . uniqid(),
                ]);

                Log::info('[TaskManagement] Task created', ['task_id' => $task->id]);
            } catch (\Throwable $e) {
                Log::error('[TaskManagement] Failed to create task', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create task: ' . $e->getMessage(),
                    'error_details' => $e->getMessage(),
                ], 500);
            }

            try {
                $task->addActivity(
                    'task_force_started',
                    'Task created via force-start API',
                    ['api' => true, 'force' => true]
                );
            } catch (\Throwable $e) {
                Log::warning('[TaskManagement] Failed to add activity log', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
                // Don't fail the whole request if activity log fails
            }

            // Dispatch job directly
            try {
                $job = new \App\Jobs\RunExamResearchJob($task->id);

                // Always use dispatch() and let Laravel handle it
                dispatch($job);

                Log::info('[TaskManagement] Job dispatched', [
                    'task_id' => $task->id,
                    'queue_driver' => config('queue.default'),
                ]);

                try {
                    $task->addActivity(
                        'job_dispatched',
                        'Job dispatched successfully',
                        ['queue_driver' => config('queue.default')]
                    );
                } catch (\Throwable $e) {
                    Log::warning('[TaskManagement] Failed to add dispatch activity log', [
                        'task_id' => $task->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('[TaskManagement] Failed to dispatch job', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                try {
                    $task->addActivity(
                        'job_dispatch_failed',
                        'Failed to dispatch job: ' . $e->getMessage(),
                        ['error' => $e->getMessage()]
                    );
                } catch (\Throwable $activityError) {
                    Log::warning('[TaskManagement] Failed to log dispatch failure', [
                        'task_id' => $task->id,
                        'error' => $activityError->getMessage(),
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Task created but job dispatch failed: ' . $e->getMessage(),
                    'task_id' => $task->id,
                    'error_details' => $e->getMessage(),
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Research task created and dispatched',
                'task' => [
                    'id' => $task->id,
                    'status' => $task->status,
                    'exam_id' => $exam->id,
                ],
                'queue_driver' => config('queue.default'),
            ]);
        } catch (\Throwable $e) {
            Log::error('[TaskManagement] forceStartResearch failed', [
                'exam_id' => $examId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Force start failed: ' . $e->getMessage(),
                'error_details' => $e->getMessage(),
                'trace' => explode("\n", $e->getTraceAsString()),
            ], 500);
        }
    }

    /**
     * Get system configuration for diagnostics
     * GET /api/diagnostics/system-config
     */
    public function getSystemConfig()
    {
        return response()->json([
            'success' => true,
            'config' => [
                'queue_driver' => config('queue.default'),
                'queue_connection' => config('queue.connections.' . config('queue.default')),
                'app_env' => app()->environment(),
                'app_debug' => config('app.debug'),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
            ],
        ]);
    }
}
