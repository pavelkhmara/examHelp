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
}
