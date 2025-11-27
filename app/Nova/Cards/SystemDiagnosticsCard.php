<?php

namespace App\Nova\Cards;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\ExamExampleQuestion;
use App\Models\GenerationLog;
use App\Models\GenerationTask;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Card;

class SystemDiagnosticsCard extends Card
{
    /**
     * The width of the card (1/3, 1/2, 2/3, full).
     *
     * @var string
     */
    public $width = 'full';

    /**
     * Get the component name for the card.
     *
     * @return string
     */
    public function component()
    {
        return 'system-diagnostics-card';
    }

    /**
     * Get the URI key for the card.
     *
     * @return string
     */
    public function uriKey()
    {
        return 'system-diagnostics';
    }

    /**
     * Prepare the card for JSON serialization.
     */
    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'stats' => $this->getStats(),
            'stuckTasks' => $this->getStuckTasks(),
            'recentFailures' => $this->getRecentFailures(),
            'apiEndpoint' => url('/api/diagnostics'),
        ]);
    }

    /**
     * Get system statistics
     */
    private function getStats(): array
    {
        $taskStatusCounts = GenerationTask::query()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
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
    }

    /**
     * Get stuck tasks (running/queued for more than 1 hour)
     */
    private function getStuckTasks(): array
    {
        $stuckTasks = GenerationTask::query()
            ->whereIn('status', ['running', 'queued'])
            ->where('updated_at', '<', now()->subHour())
            ->with('exam:id,title')
            ->orderBy('updated_at')
            ->limit(10)
            ->get()
            ->map(function ($task) {
                $stuckDuration = now()->diffForHumans($task->updated_at, ['parts' => 1]);

                return [
                    'id' => $task->id,
                    'exam_id' => $task->exam_id,
                    'exam_title' => $task->exam->title ?? 'Unknown',
                    'type' => $task->type,
                    'status' => $task->status,
                    'stuck_duration' => $stuckDuration,
                    'last_activity' => $task->updated_at?->format('Y-m-d H:i:s'),
                ];
            })
            ->toArray();

        return $stuckTasks;
    }

    /**
     * Get recent failures (last 10 failed tasks)
     */
    private function getRecentFailures(): array
    {
        $failures = GenerationTask::query()
            ->where('status', 'failed')
            ->with('exam:id,title')
            ->orderBy('updated_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($task) {
                return [
                    'id' => $task->id,
                    'exam_id' => $task->exam_id,
                    'exam_title' => $task->exam->title ?? 'Unknown',
                    'type' => $task->type,
                    'error' => substr($task->error ?? '', 0, 150).(strlen($task->error ?? '') > 150 ? '...' : ''),
                    'failed_at' => $task->updated_at?->format('Y-m-d H:i:s'),
                ];
            })
            ->toArray();

        return $failures;
    }
}
