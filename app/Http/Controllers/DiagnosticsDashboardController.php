<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\ExamExampleQuestion;
use App\Models\GenerationLog;
use App\Models\GenerationTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DiagnosticsDashboardController extends Controller
{
    /**
     * Show diagnostics dashboard
     * GET /diagnostics-dashboard
     */
    public function index()
    {
        // Get system statistics
        $taskStatusCounts = GenerationTask::query()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $stats = [
            'exams' => [
                'total' => Exam::count(),
                'active' => Exam::where('is_active', true)->count(),
                'research_completed' => Exam::where('research_status', 'completed')->count(),
                'research_running' => Exam::whereIn('research_status', ['queued', 'running', 'running_overview'])->count(),
            ],
            'tasks' => [
                'total' => GenerationTask::count(),
                'by_status' => $taskStatusCounts,
            ],
            'content' => [
                'categories' => ExamCategory::count(),
                'examples' => ExamExampleQuestion::count(),
            ],
            'logs' => [
                'total' => GenerationLog::count(),
                'total_tokens_used' => GenerationLog::sum('total_tokens'),
            ],
        ];

        // Get stuck tasks
        $stuckTasks = GenerationTask::query()
            ->whereIn('status', ['running', 'queued'])
            ->where('updated_at', '<', now()->subHour())
            ->with('exam:id,title')
            ->orderBy('updated_at')
            ->get();

        // Get recent failures
        $recentFailures = GenerationTask::query()
            ->where('status', 'failed')
            ->with('exam:id,title')
            ->orderBy('updated_at', 'desc')
            ->limit(20)
            ->get();

        // Get pending tasks
        $pendingTasks = GenerationTask::query()
            ->whereIn('status', ['pending_confirmation', 'pending_clarification'])
            ->with('exam:id,title')
            ->orderBy('updated_at', 'desc')
            ->get();

        // Get recent activity
        $recentActivity = GenerationTask::query()
            ->with('exam:id,title')
            ->orderBy('updated_at', 'desc')
            ->limit(30)
            ->get();

        // Get system configuration
        $systemConfig = [
            'queue_driver' => config('queue.default'),
            'app_env' => app()->environment(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ];

        // Get queue information
        $queueInfo = $this->getQueueInfo();

        return view('diagnostics.dashboard', compact(
            'stats',
            'stuckTasks',
            'recentFailures',
            'pendingTasks',
            'recentActivity',
            'systemConfig',
            'queueInfo'
        ));
    }

    /**
     * Diagnose a specific exam
     * GET /diagnostics-dashboard/exam/{examId}
     */
    public function diagnoseExam(string $examId)
    {
        $exam = Exam::find($examId);

        if (!$exam) {
            return response()->json([
                'success' => false,
                'error' => 'Exam not found',
                'exam_id' => $examId,
            ], 404);
        }

        // Get all tasks for this exam
        $tasks = GenerationTask::where('exam_id', $exam->id)
            ->orderBy('created_at', 'desc')
            ->get();

        // Get research tasks only
        $researchTasks = $tasks->where('type', 'research');

        // Group by status
        $tasksByStatus = $researchTasks->groupBy('status')
            ->map(function ($group) {
                return $group->count();
            })
            ->toArray();

        // Get active tasks
        $activeTasks = $researchTasks->whereIn('status', ['queued', 'running'])
            ->map(function ($task) {
                $stuckMinutes = now()->diffInMinutes($task->updated_at);
                return [
                    'id' => $task->id,
                    'status' => $task->status,
                    'created_at' => $task->created_at->toISOString(),
                    'created_ago' => $task->created_at->diffForHumans(),
                    'updated_at' => $task->updated_at->toISOString(),
                    'updated_ago' => $task->updated_at->diffForHumans(),
                    'stuck_minutes' => $stuckMinutes,
                    'is_stuck' => $stuckMinutes > 60,
                    'idempotency_key' => $task->idempotency_key,
                ];
            })
            ->values();

        // Get recent tasks (last 5)
        $recentTasks = $tasks->take(5)->map(function ($task) {
            $duration = $task->updated_at->diffInMinutes($task->created_at);
            return [
                'id' => $task->id,
                'type' => $task->type,
                'status' => $task->status,
                'created_at' => $task->created_at->toISOString(),
                'created_ago' => $task->created_at->diffForHumans(),
                'updated_at' => $task->updated_at->toISOString(),
                'duration_minutes' => $duration,
                'attempts' => $task->attempts,
                'error' => $task->error,
                'idempotency_key' => $task->idempotency_key,
            ];
        });

        // Determine issue and suggestions
        $issue = null;
        $suggestions = [];

        if ($tasks->isEmpty()) {
            $issue = 'no_tasks';
            $suggestions = [
                'No tasks found for this exam. This explains why nothing happens when you click "Run Exam Research".',
                'The action likely tries to return an existing task, but there are none.',
                'Use the "Force Start" button below to create a new task.',
            ];
        } elseif ($activeTasks->count() > 0) {
            $issue = 'active_tasks_blocking';
            $suggestions = [
                "Found {$activeTasks->count()} active task(s). This explains why new tasks are not created.",
                'TaskDispatcher prevents duplicate tasks when one is already running.',
                'Wait for the task to complete, or cancel stuck tasks and force start a new one.',
            ];

            foreach ($activeTasks as $task) {
                if ($task['is_stuck']) {
                    $suggestions[] = "Task #{$task['id']} appears to be STUCK (last updated {$task['stuck_minutes']} minutes ago). Consider cancelling it.";
                }
            }
        }

        return response()->json([
            'success' => true,
            'exam' => [
                'id' => $exam->id,
                'title' => $exam->title,
                'slug' => $exam->slug,
                'research_status' => $exam->research_status,
                'analysis_status' => $exam->analysis_status,
                'created_at' => $exam->created_at->toISOString(),
                'created_ago' => $exam->created_at->diffForHumans(),
            ],
            'tasks' => [
                'total' => $tasks->count(),
                'research_total' => $researchTasks->count(),
                'by_status' => $tasksByStatus,
            ],
            'active_tasks' => $activeTasks,
            'recent_tasks' => $recentTasks,
            'issue' => $issue,
            'suggestions' => $suggestions,
            'system' => [
                'queue_driver' => config('queue.default'),
                'environment' => app()->environment(),
            ],
        ]);
    }

    /**
     * Get queue information
     */
    private function getQueueInfo(): array
    {
        $queueDriver = config('queue.default');
        $queueInfo = [
            'driver' => $queueDriver,
            'pending_jobs' => collect([]),
            'failed_jobs' => collect([]),
            'total_pending' => 0,
            'total_failed' => 0,
        ];

        try {
            if ($queueDriver === 'database') {
                // Get pending jobs
                if (\Schema::hasTable('jobs')) {
                    $queueInfo['pending_jobs'] = \DB::table('jobs')
                        ->orderBy('created_at')
                        ->limit(50)
                        ->get()
                        ->map(function ($job) {
                            $payload = json_decode($job->payload, true);

                            // Try to extract task ID from job data
                            $taskId = null;
                            try {
                                $jobData = unserialize($payload['data']['command'] ?? '');
                                if (is_object($jobData) && property_exists($jobData, 'taskId')) {
                                    $taskId = $jobData->taskId;
                                }
                            } catch (\Exception $e) {
                                // Ignore unserialize errors
                            }

                            return (object) [
                                'id' => $job->id,
                                'queue' => $job->queue,
                                'attempts' => $job->attempts,
                                'reserved_at' => $job->reserved_at ? \Carbon\Carbon::createFromTimestamp($job->reserved_at) : null,
                                'available_at' => \Carbon\Carbon::createFromTimestamp($job->available_at),
                                'created_at' => \Carbon\Carbon::createFromTimestamp($job->created_at),
                                'job_class' => $payload['displayName'] ?? 'Unknown',
                                'task_id' => $taskId,
                            ];
                        });

                    $queueInfo['total_pending'] = \DB::table('jobs')->count();
                }

                // Get failed jobs
                if (\Schema::hasTable('failed_jobs')) {
                    $queueInfo['failed_jobs'] = \DB::table('failed_jobs')
                        ->orderBy('failed_at', 'desc')
                        ->limit(20)
                        ->get()
                        ->map(function ($job) {
                            $payload = json_decode($job->payload, true);

                            return (object) [
                                'id' => $job->id,
                                'uuid' => $job->uuid ?? null,
                                'connection' => $job->connection,
                                'queue' => $job->queue,
                                'job_class' => $payload['displayName'] ?? 'Unknown',
                                'exception' => $job->exception,
                                'failed_at' => \Carbon\Carbon::parse($job->failed_at),
                            ];
                        });

                    $queueInfo['total_failed'] = \DB::table('failed_jobs')->count();
                }
            }
        } catch (\Exception $e) {
            $queueInfo['error'] = $e->getMessage();
        }

        return $queueInfo;
    }
}
