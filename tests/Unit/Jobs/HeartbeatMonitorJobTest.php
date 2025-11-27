<?php

namespace Tests\Unit\Jobs;

use App\Jobs\HeartbeatMonitorJob;
use App\Models\GenerationTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Тесты для HeartbeatMonitorJob - детекция stalled tasks
 */
class HeartbeatMonitorJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Тест: детектит stalled tasks с старым heartbeat_at (> 10 min)
     */
    public function test_detects_stalled_tasks_with_old_heartbeat(): void
    {
        // Arrange
        Log::shouldReceive('debug')->never(); // debug() called only when NO stalled tasks
        Log::shouldReceive('warning')->twice(); // 1x summary + 1x per task

        $stalledTask = GenerationTask::factory()->create([
            'status' => 'running',
            'heartbeat_at' => now()->subMinutes(15), // 15 min ago (stale)
            'activities' => [],
        ]);

        $freshTask = GenerationTask::factory()->create([
            'status' => 'running',
            'heartbeat_at' => now()->subMinutes(3), // 3 min ago (fresh)
        ]);

        // Act
        $job = new HeartbeatMonitorJob;
        $job->handle();

        // Assert: stalled task should have activity log entry
        $stalledTask->refresh();
        $this->assertNotEmpty($stalledTask->activities);
        $this->assertCount(1, $stalledTask->activities);

        $activity = $stalledTask->activities[0];
        $this->assertEquals('stall_detected', $activity['event']);
        $this->assertStringContainsString('no heartbeat for 10 minutes', $activity['message']);
        $this->assertArrayHasKey('last_heartbeat', $activity['context']);

        // Fresh task should NOT have stall activity
        $freshTask->refresh();
        $this->assertEmpty($freshTask->activities);
    }

    /**
     * Тест: детектит tasks с null heartbeat_at
     */
    public function test_detects_tasks_with_null_heartbeat(): void
    {
        // Arrange
        Log::shouldReceive('debug')->never();
        Log::shouldReceive('warning')->twice(); // 1x summary + 1x per task

        $taskWithoutHeartbeat = GenerationTask::factory()->create([
            'status' => 'running',
            'heartbeat_at' => null, // Never sent heartbeat
            'activities' => [],
        ]);

        // Act
        $job = new HeartbeatMonitorJob;
        $job->handle();

        // Assert
        $taskWithoutHeartbeat->refresh();
        $this->assertNotEmpty($taskWithoutHeartbeat->activities);

        $activity = $taskWithoutHeartbeat->activities[0];
        $this->assertEquals('stall_detected', $activity['event']);
        $this->assertNull($activity['context']['last_heartbeat']);
    }

    /**
     * Тест: игнорирует tasks с recent heartbeat (< 10 min)
     */
    public function test_ignores_tasks_with_recent_heartbeat(): void
    {
        // Arrange
        Log::shouldReceive('debug')->once(); // Should log "no stalled tasks"
        Log::shouldReceive('warning')->never(); // No warnings

        $freshTask1 = GenerationTask::factory()->create([
            'status' => 'running',
            'heartbeat_at' => now()->subMinutes(3),
        ]);

        $freshTask2 = GenerationTask::factory()->create([
            'status' => 'running',
            'heartbeat_at' => now()->subMinutes(9), // Just under threshold
        ]);

        // Act
        $job = new HeartbeatMonitorJob;
        $job->handle();

        // Assert: no activities added
        $freshTask1->refresh();
        $freshTask2->refresh();
        $this->assertEmpty($freshTask1->activities);
        $this->assertEmpty($freshTask2->activities);
    }

    /**
     * Тест: добавляет activity log entry к stalled tasks
     */
    public function test_adds_activity_log_to_stalled_tasks(): void
    {
        // Arrange
        Log::shouldReceive('debug')->never();
        Log::shouldReceive('warning')->twice();

        $existingActivities = [
            [
                'timestamp' => now()->subMinutes(20)->toIso8601String(),
                'event' => 'started',
                'message' => 'Task started',
            ],
        ];

        $stalledTask = GenerationTask::factory()->create([
            'status' => 'running',
            'heartbeat_at' => now()->subMinutes(12),
            'activities' => $existingActivities,
        ]);

        // Act
        $job = new HeartbeatMonitorJob;
        $job->handle();

        // Assert: activity log appended (not replaced)
        $stalledTask->refresh();
        $this->assertCount(2, $stalledTask->activities, 'Should have 2 activities (existing + new)');

        $lastActivity = $stalledTask->activities[1];
        $this->assertEquals('stall_detected', $lastActivity['event']);
        $this->assertArrayHasKey('timestamp', $lastActivity);
        $this->assertArrayHasKey('context', $lastActivity);
        $this->assertArrayHasKey('detection_time', $lastActivity['context']);
    }
}
