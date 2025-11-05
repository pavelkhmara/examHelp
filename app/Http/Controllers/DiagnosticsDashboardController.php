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
