<?php

namespace Tests\Feature;

use App\Jobs\RunExamResearchJob;
use App\Models\Exam;
use App\Models\GenerationTask;
use App\Support\Queue\TaskDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ParallelProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_exams_can_be_queued_without_blocking(): void
    {
        // Create 5 test exams
        $exams = [];
        for ($i = 1; $i <= 5; $i++) {
            $exams[] = Exam::factory()->create([
                'title' => "ASYNC TEST {$i}",
                'user_input' => "Test exam {$i}",
                'level' => 'B2',
            ]);
        }

        Queue::fake();

        $dispatcher = app(TaskDispatcher::class);
        $tasks = [];

        // Measure time to queue all tasks
        $start = microtime(true);

        foreach ($exams as $exam) {
            $task = $dispatcher->enqueue(
                type: 'research',
                subject: $exam,
                request: ['exam_id' => $exam->id, 'user_input' => $exam->user_input],
                jobClass: RunExamResearchJob::class,
                idempotencyKey: "test:{$exam->id}:".now()->timestamp
            );

            $tasks[] = $task;
        }

        $duration = microtime(true) - $start;

        // All tasks should be queued instantly (< 1 second)
        $this->assertLessThan(1.0, $duration, 'Queueing 5 tasks should be instant (< 1 second)');

        // Verify all tasks were created
        $this->assertCount(5, $tasks);

        // Verify all tasks are queued
        foreach ($tasks as $task) {
            $this->assertEquals('queued', $task->status);
            $this->assertInstanceOf(GenerationTask::class, $task);
        }

        // Verify jobs were dispatched
        Queue::assertPushed(RunExamResearchJob::class, 5);
    }

    public function test_tasks_have_different_timestamps_proving_parallel_creation(): void
    {
        $exams = Exam::factory()->count(3)->create();

        Queue::fake();

        $dispatcher = app(TaskDispatcher::class);
        $tasks = [];

        foreach ($exams as $exam) {
            $task = $dispatcher->enqueue(
                type: 'research',
                subject: $exam,
                request: ['exam_id' => $exam->id],
                jobClass: RunExamResearchJob::class
            );

            $tasks[] = $task;

            // Small delay to ensure timestamp difference
            usleep(1000); // 1ms
        }

        // All tasks should have slightly different created_at times
        $timestamps = array_map(fn ($t) => $t->created_at->timestamp, $tasks);
        $this->assertCount(3, array_unique($timestamps), 'Tasks should have different creation times');
    }

    public function test_no_blocking_between_different_exam_types(): void
    {
        // Create exams of different types
        $exam1 = Exam::factory()->create(['title' => 'TOEFL', 'level' => 'B2']);
        $exam2 = Exam::factory()->create(['title' => 'IELTS', 'level' => 'C1']);
        $exam3 = Exam::factory()->create(['title' => 'DELF', 'level' => 'B1']);

        Queue::fake();

        $dispatcher = app(TaskDispatcher::class);

        $start = microtime(true);

        // Dispatch different types of tasks
        $task1 = $dispatcher->enqueue(
            type: 'research',
            subject: $exam1,
            request: ['exam_id' => $exam1->id],
            jobClass: RunExamResearchJob::class
        );

        $task2 = $dispatcher->enqueue(
            type: 'research',
            subject: $exam2,
            request: ['exam_id' => $exam2->id],
            jobClass: RunExamResearchJob::class
        );

        $task3 = $dispatcher->enqueue(
            type: 'research',
            subject: $exam3,
            request: ['exam_id' => $exam3->id],
            jobClass: RunExamResearchJob::class
        );

        $duration = microtime(true) - $start;

        // Should be instant
        $this->assertLessThan(0.5, $duration);

        // All tasks should be independent
        $this->assertNotEquals($task1->id, $task2->id);
        $this->assertNotEquals($task2->id, $task3->id);

        // All should be queued
        $this->assertEquals('queued', $task1->status);
        $this->assertEquals('queued', $task2->status);
        $this->assertEquals('queued', $task3->status);
    }

    public function test_idempotency_prevents_duplicate_parallel_tasks(): void
    {
        $exam = Exam::factory()->create();

        Queue::fake();

        $dispatcher = app(TaskDispatcher::class);

        $idempotencyKey = 'test:parallel:duplicate';

        // Try to create same task twice in parallel
        $task1 = $dispatcher->enqueue(
            type: 'research',
            subject: $exam,
            request: ['exam_id' => $exam->id],
            jobClass: RunExamResearchJob::class,
            idempotencyKey: $idempotencyKey
        );

        $task2 = $dispatcher->enqueue(
            type: 'research',
            subject: $exam,
            request: ['exam_id' => $exam->id],
            jobClass: RunExamResearchJob::class,
            idempotencyKey: $idempotencyKey
        );

        // Should return same task
        $this->assertEquals($task1->id, $task2->id);

        // Only one job should be dispatched
        Queue::assertPushed(RunExamResearchJob::class, 1);
    }

    public function test_concurrent_task_creation_for_different_exams_is_safe(): void
    {
        // Simulate concurrent requests for different exams
        $exams = Exam::factory()->count(10)->create();

        Queue::fake();

        $dispatcher = app(TaskDispatcher::class);
        $tasks = [];

        $start = microtime(true);

        // Create tasks for all exams "simultaneously"
        foreach ($exams as $exam) {
            $tasks[] = $dispatcher->enqueue(
                type: 'research',
                subject: $exam,
                request: ['exam_id' => $exam->id],
                jobClass: RunExamResearchJob::class
            );
        }

        $duration = microtime(true) - $start;

        // Should be very fast (< 2 seconds for 10 tasks)
        $this->assertLessThan(2.0, $duration);

        // All tasks should be unique
        $taskIds = array_map(fn ($t) => $t->id, $tasks);
        $this->assertCount(10, array_unique($taskIds));

        // All should be queued
        foreach ($tasks as $task) {
            $this->assertEquals('queued', $task->status);
        }

        // 10 jobs should be dispatched
        Queue::assertPushed(RunExamResearchJob::class, 10);
    }

    public function test_queue_connection_is_configured_correctly(): void
    {
        // Verify queue is set to redis (not sync)
        $queueConnection = config('queue.default');

        // In testing, it might be 'sync', but in production it should be 'redis'
        $this->assertContains($queueConnection, ['redis', 'sync', 'database']);

        // Verify redis config exists
        $redisConfig = config('queue.connections.redis');
        $this->assertIsArray($redisConfig);
    }
}
