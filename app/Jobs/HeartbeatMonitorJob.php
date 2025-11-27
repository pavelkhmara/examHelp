<?php

namespace App\Jobs;

use App\Models\GenerationTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * HeartbeatMonitor Job
 *
 * Monitors running GenerationTasks and detects stalled tasks.
 * A task is considered stalled if:
 * - status is 'running'
 * - heartbeat_at is older than STALL_THRESHOLD_MINUTES (default: 10 min)
 * - OR heartbeat_at is null for tasks running longer than threshold
 *
 * This job runs periodically via Laravel scheduler (every 5 minutes).
 */
class HeartbeatMonitorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Time threshold in minutes for considering a task stalled
     */
    public const STALL_THRESHOLD_MINUTES = 10;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $stalledThreshold = now()->subMinutes(self::STALL_THRESHOLD_MINUTES);

        // Find all running tasks with stale heartbeat
        $stalledTasks = GenerationTask::where('status', 'running')
            ->where(function ($q) use ($stalledThreshold) {
                $q->whereNull('heartbeat_at')
                    ->orWhere('heartbeat_at', '<', $stalledThreshold);
            })
            ->get();

        if ($stalledTasks->isEmpty()) {
            Log::debug('HeartbeatMonitor: No stalled tasks found', [
                'threshold_minutes' => self::STALL_THRESHOLD_MINUTES,
            ]);

            return;
        }

        Log::warning('HeartbeatMonitor: Detected stalled tasks', [
            'count' => $stalledTasks->count(),
            'task_ids' => $stalledTasks->pluck('id')->toArray(),
            'threshold_minutes' => self::STALL_THRESHOLD_MINUTES,
        ]);

        foreach ($stalledTasks as $task) {
            // Log details about the stalled task
            $stalledSince = $task->heartbeat_at
                ? $task->heartbeat_at->diffForHumans()
                : 'never (no heartbeat recorded)';

            Log::warning('Stalled task detected', [
                'task_id' => $task->id,
                'type' => $task->type,
                'exam_id' => $task->exam_id,
                'status' => $task->status,
                'heartbeat_at' => $task->heartbeat_at?->toDateTimeString(),
                'stalled_since' => $stalledSince,
                'created_at' => $task->created_at?->toDateTimeString(),
            ]);

            // Add activity log entry
            $activities = $task->activities ?? [];
            $activities[] = [
                'timestamp' => now()->toIso8601String(),
                'event' => 'stall_detected',
                'message' => 'Task stalled - no heartbeat for '.self::STALL_THRESHOLD_MINUTES.' minutes',
                'context' => [
                    'last_heartbeat' => $task->heartbeat_at?->toDateTimeString(),
                    'detection_time' => now()->toDateTimeString(),
                ],
            ];
            $task->activities = $activities;
            $task->save();

            // Note: We don't auto-cancel stalled tasks
            // Instead, we rely on StalledTaskCard to notify operators
            // and allow manual intervention via CancelStalledTaskAction
        }
    }
}
