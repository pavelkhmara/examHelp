<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\ExamExampleQuestion;
use App\Models\GenerationTask;
use App\Models\GenerationLog;
use Illuminate\Http\Request;

/**
 * Diagnostics API endpoints for monitoring and testing
 * These endpoints provide read-only access to system state
 */
class DiagnosticsController extends Controller
{
    /**
     * Get list of all exams with basic info
     * GET /api/diagnostics/exams
     */
    public function exams()
    {
        $exams = Exam::query()
            ->orderBy('created_at', 'desc')
            ->take(20)
            ->get()
            ->map(function ($exam) {
                return [
                    'id' => $exam->id,
                    'title' => $exam->title,
                    'slug' => $exam->slug,
                    'level' => $exam->level,
                    'research_status' => $exam->research_status,
                    'analysis_status' => $exam->analysis_status,
                    'categories_count' => $exam->categories_count ?? 0,
                    'examples_count' => $exam->examples_count ?? 0,
                    'created_at' => $exam->created_at?->toISOString(),
                    'updated_at' => $exam->updated_at?->toISOString(),
                ];
            });

        return response()->json([
            'success' => true,
            'count' => $exams->count(),
            'exams' => $exams,
        ]);
    }

    /**
     * Get detailed exam information
     * GET /api/diagnostics/exams/{examId}
     */
    public function exam(string $examId)
    {
        $exam = Exam::query()->findOrFail($examId);

        $latestTask = $exam->generationTasks()->latest()->first();

        return response()->json([
            'success' => true,
            'exam' => [
                'id' => $exam->id,
                'title' => $exam->title,
                'slug' => $exam->slug,
                'level' => $exam->level,
                'description' => $exam->description,
                'is_active' => $exam->is_active,
                'research_status' => $exam->research_status,
                'analysis_status' => $exam->analysis_status,
                'categories_count' => $exam->categories_count ?? 0,
                'examples_count' => $exam->examples_count ?? 0,
                'user_input' => $exam->user_input,
                'identity' => $exam->identity,
                'exam_structure' => $exam->exam_structure,
                'created_at' => $exam->created_at?->toISOString(),
                'updated_at' => $exam->updated_at?->toISOString(),
            ],
            'latest_task' => $latestTask ? [
                'id' => $latestTask->id,
                'type' => $latestTask->type,
                'status' => $latestTask->status,
                'attempts' => $latestTask->attempts,
                'error' => $latestTask->error,
                'created_at' => $latestTask->created_at?->toISOString(),
                'updated_at' => $latestTask->updated_at?->toISOString(),
            ] : null,
            'documents_count' => $exam->documents()->count(),
            'tasks_count' => $exam->generationTasks()->count(),
            'logs_count' => $exam->generationLogs()->count(),
        ]);
    }

    /**
     * Get generation tasks for an exam
     * GET /api/diagnostics/exams/{examId}/tasks
     */
    public function examTasks(string $examId)
    {
        $exam = Exam::query()->findOrFail($examId);

        $tasks = $exam->generationTasks()
            ->orderBy('created_at', 'desc')
            ->take(50)
            ->get()
            ->map(function ($task) {
                return [
                    'id' => $task->id,
                    'type' => $task->type,
                    'status' => $task->status,
                    'attempts' => $task->attempts,
                    'idempotency_key' => $task->idempotency_key,
                    'error' => $task->error ? substr($task->error, 0, 200) : null,
                    'request_source' => $task->request['source'] ?? null,
                    'activities_count' => is_array($task->activities) ? count($task->activities) : 0,
                    'created_at' => $task->created_at?->toISOString(),
                    'updated_at' => $task->updated_at?->toISOString(),
                ];
            });

        // Count tasks by status
        $statusCounts = $exam->generationTasks()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'success' => true,
            'exam_id' => $exam->id,
            'exam_title' => $exam->title,
            'total_tasks' => $tasks->count(),
            'status_counts' => $statusCounts,
            'tasks' => $tasks,
        ]);
    }

    /**
     * Get detailed task information with activities
     * GET /api/diagnostics/tasks/{taskId}
     */
    public function task(int $taskId)
    {
        $task = GenerationTask::query()->findOrFail($taskId);

        return response()->json([
            'success' => true,
            'task' => [
                'id' => $task->id,
                'exam_id' => $task->exam_id,
                'type' => $task->type,
                'status' => $task->status,
                'attempts' => $task->attempts,
                'idempotency_key' => $task->idempotency_key,
                'error' => $task->error,
                'request' => $task->request,
                'result' => $task->result,
                'activities' => $task->activities,
                'created_at' => $task->created_at?->toISOString(),
                'updated_at' => $task->updated_at?->toISOString(),
            ],
            'exam' => [
                'id' => $task->exam->id,
                'title' => $task->exam->title,
            ],
        ]);
    }

    /**
     * Get generation logs for an exam
     * GET /api/diagnostics/exams/{examId}/logs
     */
    public function examLogs(string $examId)
    {
        $exam = Exam::query()->findOrFail($examId);

        $logs = $exam->generationLogs()
            ->orderBy('created_at', 'desc')
            ->take(100)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'generation_task_id' => $log->generation_task_id,
                    'stage' => $log->stage,
                    'prompt_tokens' => $log->prompt_tokens,
                    'completion_tokens' => $log->completion_tokens,
                    'total_tokens' => $log->total_tokens,
                    'model' => $log->model,
                    'duration_ms' => $log->duration_ms,
                    'created_at' => $log->created_at?->toISOString(),
                ];
            });

        // Calculate total tokens used
        $totalTokens = $exam->generationLogs()->sum('total_tokens');

        return response()->json([
            'success' => true,
            'exam_id' => $exam->id,
            'exam_title' => $exam->title,
            'total_logs' => $logs->count(),
            'total_tokens_used' => $totalTokens,
            'logs' => $logs,
        ]);
    }

    /**
     * Get categories for an exam
     * GET /api/diagnostics/exams/{examId}/categories
     */
    public function examCategories(string $examId)
    {
        $exam = Exam::query()->findOrFail($examId);

        $categories = $exam->categories()
            ->orderBy('order')
            ->get()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'order' => $category->order,
                    'slug' => $category->slug,
                    'duration_min' => $category->duration_min,
                    'task_types' => $category->task_types,
                    'created_at' => $category->created_at?->toISOString(),
                ];
            });

        return response()->json([
            'success' => true,
            'exam_id' => $exam->id,
            'exam_title' => $exam->title,
            'total_categories' => $categories->count(),
            'categories' => $categories,
        ]);
    }

    /**
     * Get example questions for an exam
     * GET /api/diagnostics/exams/{examId}/examples
     */
    public function examExamples(string $examId)
    {
        $exam = Exam::query()->findOrFail($examId);

        $examples = $exam->examples()
            ->orderBy('created_at', 'desc')
            ->take(50)
            ->get()
            ->map(function ($example) {
                return [
                    'id' => $example->id,
                    'category_id' => $example->exam_category_id,
                    'question_text' => substr($example->question_text ?? '', 0, 200),
                    'task_type' => $example->task_type,
                    'difficulty' => $example->difficulty,
                    'created_at' => $example->created_at?->toISOString(),
                ];
            });

        return response()->json([
            'success' => true,
            'exam_id' => $exam->id,
            'exam_title' => $exam->title,
            'total_examples' => $examples->count(),
            'examples' => $examples,
        ]);
    }

    /**
     * Get system statistics
     * GET /api/diagnostics/stats
     */
    public function stats()
    {
        return response()->json([
            'success' => true,
            'stats' => [
                'exams' => [
                    'total' => Exam::count(),
                    'active' => Exam::where('is_active', true)->count(),
                    'research_completed' => Exam::where('research_status', 'completed')->count(),
                    'research_running' => Exam::whereIn('research_status', ['queued', 'running', 'running_overview'])->count(),
                ],
                'tasks' => [
                    'total' => GenerationTask::count(),
                    'queued' => GenerationTask::where('status', 'queued')->count(),
                    'running' => GenerationTask::where('status', 'running')->count(),
                    'completed' => GenerationTask::where('status', 'completed')->count(),
                    'failed' => GenerationTask::where('status', 'failed')->count(),
                    'pending_confirmation' => GenerationTask::where('status', 'pending_confirmation')->count(),
                    'pending_clarification' => GenerationTask::where('status', 'pending_clarification')->count(),
                    'cancelled' => GenerationTask::where('status', 'cancelled')->count(),
                ],
                'content' => [
                    'categories' => ExamCategory::count(),
                    'examples' => ExamExampleQuestion::count(),
                ],
                'logs' => [
                    'total' => GenerationLog::count(),
                    'total_tokens_used' => GenerationLog::sum('total_tokens'),
                ],
            ],
        ]);
    }

    /**
     * Get recent activity (last 20 tasks)
     * GET /api/diagnostics/activity
     */
    public function activity()
    {
        $recentTasks = GenerationTask::query()
            ->with('exam:id,title')
            ->orderBy('created_at', 'desc')
            ->take(20)
            ->get()
            ->map(function ($task) {
                return [
                    'id' => $task->id,
                    'exam_id' => $task->exam_id,
                    'exam_title' => $task->exam->title ?? 'Unknown',
                    'type' => $task->type,
                    'status' => $task->status,
                    'idempotency_key' => $task->idempotency_key,
                    'source' => $task->request['source'] ?? null,
                    'created_at' => $task->created_at?->toISOString(),
                    'updated_at' => $task->updated_at?->toISOString(),
                ];
            });

        return response()->json([
            'success' => true,
            'recent_tasks' => $recentTasks,
        ]);
    }

    /**
     * Check if Nova actions are registered
     * GET /api/diagnostics/actions
     */
    public function actions()
    {
        try {
            $exam = Exam::first();

            if (!$exam) {
                return response()->json([
                    'success' => false,
                    'message' => 'No exams found in database',
                ]);
            }

            $resource = new \App\Nova\Exam($exam);
            $actions = $resource->actions(request());

            $actionsList = collect($actions)->map(function ($action) {
                return [
                    'class' => get_class($action),
                    'name' => $action->name ?? 'Unknown',
                ];
            })->values();

            return response()->json([
                'success' => true,
                'exam_id' => $exam->id,
                'actions_count' => $actionsList->count(),
                'actions' => $actionsList,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Health check endpoint
     * GET /api/diagnostics/health
     */
    public function health()
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toISOString(),
            'environment' => app()->environment(),
        ]);
    }
}
