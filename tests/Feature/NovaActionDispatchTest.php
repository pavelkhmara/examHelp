<?php

namespace Tests\Feature;

use App\Jobs\RunExamResearchJob;
use App\Models\Exam;
use App\Models\GenerationTask;
use App\Support\Queue\TaskDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NovaActionDispatchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that TaskDispatcher properly creates tasks and dispatches jobs
     */
    public function test_task_dispatcher_creates_and_dispatches_job()
    {
        // Enable queue monitoring
        Queue::fake();

        // Create exam
        $exam = Exam::factory()->create([
            'title' => 'Test Exam',
            'user_input' => json_encode(['exam_name' => 'IELTS']),
        ]);

        // Simulate Nova Action behavior
        $taskDispatcher = app(TaskDispatcher::class);

        $payload = [
            'exam_id' => $exam->id,
            'source' => 'nova_action_test',
            'user_input' => ['exam_name' => 'IELTS'],
            'document_id' => null,
            'without_confirmation' => true,
            'overview_model' => 'gpt-5-mini',
        ];

        $idempotencyKey = "exam:{$exam->id}:research:test:" . time() . ':' . uniqid();

        Log::info('[TEST] Before enqueue', [
            'exam_id' => $exam->id,
            'idempotency_key' => $idempotencyKey,
        ]);

        // Enqueue task using TaskDispatcher
        $task = $taskDispatcher->enqueue(
            type: 'research',
            subject: $exam,
            request: $payload,
            jobClass: RunExamResearchJob::class,
            idempotencyKey: $idempotencyKey,
            queue: null
        );

        Log::info('[TEST] After enqueue', [
            'task_id' => $task->id,
            'task_status' => $task->status,
        ]);

        // Assert task was created
        $this->assertNotNull($task);
        $this->assertInstanceOf(GenerationTask::class, $task);
        $this->assertEquals('queued', $task->status);
        $this->assertEquals($exam->id, $task->exam_id);
        $this->assertEquals('research', $task->type);

        // Assert job was dispatched
        Queue::assertPushed(RunExamResearchJob::class, function ($job) use ($task) {
            return $job->taskId === $task->id;
        });

        Log::info('[TEST] Test passed', [
            'task_id' => $task->id,
            'assertions' => 'all passed',
        ]);
    }

    /**
     * Test that TaskDispatcher returns existing task if one is already running
     */
    public function test_task_dispatcher_returns_existing_running_task()
    {
        // Prevent observer jobs from interfering
        Queue::fake([
            \App\Jobs\RunExamResearchJob::class,
        ]);

        $exam = Exam::factory()->create();

        // Create existing running task
        $existingTask = GenerationTask::create([
            'exam_id' => $exam->id,
            'subject_type' => get_class($exam),
            'subject_id' => $exam->id,
            'type' => 'research',
            'status' => 'running',
            'request' => ['exam_id' => $exam->id],
            'attempts' => 0,
            'idempotency_key' => 'existing-key',
        ]);

        Log::info('[TEST] Created existing running task', ['task_id' => $existingTask->id]);

        // Try to create new task with same type for same exam
        $taskDispatcher = app(TaskDispatcher::class);

        $newTask = $taskDispatcher->enqueue(
            type: 'research',
            subject: $exam,
            request: ['exam_id' => $exam->id, 'source' => 'test'],
            jobClass: RunExamResearchJob::class,
            idempotencyKey: 'new-key',
            queue: null
        );

        Log::info('[TEST] After enqueue attempt', [
            'returned_task_id' => $newTask->id,
            'existing_task_id' => $existingTask->id,
        ]);

        // Should return existing task
        $this->assertEquals($existingTask->id, $newTask->id);

        // Should NOT dispatch a new job (returns existing)
        Queue::assertNothingPushed();

        Log::info('[TEST] Test passed - existing task returned');
    }

    /**
     * Test that TaskDispatcher creates new task after previous one completes
     */
    public function test_task_dispatcher_creates_new_task_after_completion()
    {
        Queue::fake();

        $exam = Exam::factory()->create();

        // Create completed task
        $completedTask = GenerationTask::create([
            'exam_id' => $exam->id,
            'subject_type' => get_class($exam),
            'subject_id' => $exam->id,
            'type' => 'research',
            'status' => 'completed',
            'request' => ['exam_id' => $exam->id],
            'attempts' => 1,
            'idempotency_key' => 'completed-key',
        ]);

        Log::info('[TEST] Created completed task', ['task_id' => $completedTask->id]);

        // Try to create new task - should succeed since old one is completed
        $taskDispatcher = app(TaskDispatcher::class);

        $newTask = $taskDispatcher->enqueue(
            type: 'research',
            subject: $exam,
            request: ['exam_id' => $exam->id, 'source' => 'test_after_completion'],
            jobClass: RunExamResearchJob::class,
            idempotencyKey: 'new-key-after-completion',
            queue: null
        );

        Log::info('[TEST] After enqueue attempt', [
            'new_task_id' => $newTask->id,
            'completed_task_id' => $completedTask->id,
        ]);

        // Should create NEW task (not return completed one)
        $this->assertNotEquals($completedTask->id, $newTask->id);
        $this->assertEquals('queued', $newTask->status);

        // Should dispatch job for new task
        Queue::assertPushed(RunExamResearchJob::class, function ($job) use ($newTask) {
            return $job->taskId === $newTask->id;
        });

        Log::info('[TEST] Test passed - new task created after completion');
    }

    /**
     * Test redundant dispatch in Nova Action
     */
    public function test_redundant_dispatch_in_nova_action()
    {
        Queue::fake();

        $exam = Exam::factory()->create();

        // Simulate Nova Action with redundant dispatch
        $taskDispatcher = app(TaskDispatcher::class);

        $payload = [
            'exam_id' => $exam->id,
            'source' => 'nova_action_redundant',
        ];

        $idempotencyKey = "exam:{$exam->id}:research:redundant:" . time();

        // First dispatch via TaskDispatcher
        $task = $taskDispatcher->enqueue(
            type: 'research',
            subject: $exam,
            request: $payload,
            jobClass: RunExamResearchJob::class,
            idempotencyKey: $idempotencyKey,
            queue: null
        );

        Log::info('[TEST] Task created via dispatcher', ['task_id' => $task->id]);

        // Redundant dispatch (like in Nova Action line 118)
        try {
            dispatch(new RunExamResearchJob($task->id));
            Log::info('[TEST] Redundant dispatch succeeded');
        } catch (\Throwable $e) {
            Log::error('[TEST] Redundant dispatch failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        // Both dispatches should succeed
        Queue::assertPushed(RunExamResearchJob::class, 2);

        Log::info('[TEST] Test passed - redundant dispatch works');
    }

    /**
     * Test actual Nova Action behavior (simulate exactly)
     */
    public function test_simulate_nova_action_exact_behavior()
    {
        Queue::fake();

        $exam = Exam::factory()->create([
            'user_input' => json_encode(['exam_name' => 'IELTS']),
        ]);

        // Simulate EXACT Nova Action code
        $tasks = app(TaskDispatcher::class);
        $models = collect([$exam]);

        // From ResearchAction.php lines 72-92
        $userInput = [];
        if (!empty($exam->user_input)) {
            if (is_array($exam->user_input)) {
                $userInput = $exam->user_input;
            }
        }

        $documentId = null;
        $lastDoc = $exam->documents()->latest()->first();
        if ($lastDoc) {
            $documentId = $lastDoc->id;
        }

        $payload = [
            'exam_id' => $exam->id,
            'source' => 'nova_action',
            'user_input' => $userInput,
            'document_id' => $documentId,
            'without_confirmation' => true,
            'overview_model' => 'gpt-5-mini',
        ];

        $requestIdempotencyKey = "exam:{$exam->id}:research:nova:" . time() . ':' . uniqid();

        // Enqueue research task (line 85-92)
        $task = $tasks->enqueue(
            type: 'research',
            subject: $exam,
            request: $payload,
            jobClass: RunExamResearchJob::class,
            idempotencyKey: $requestIdempotencyKey,
            queue: null
        );

        Log::info('[TEST] Task created', [
            'task_id' => $task->id,
            'status' => $task->status,
        ]);

        // Check if new task (line 106)
        $isNew = ($task->idempotency_key === $requestIdempotencyKey);

        $this->assertTrue($isNew, 'Task should be new');

        // Redundant dispatch (lines 117-127)
        if ($isNew) {
            try {
                dispatch(new RunExamResearchJob($task->id));
                Log::info('[TEST] Redundant dispatch succeeded', ['task_id' => $task->id]);
            } catch (\Throwable $e) {
                Log::error('[TEST] Redundant dispatch failed', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Assert job was dispatched (twice: once by TaskDispatcher, once redundant)
        Queue::assertPushed(RunExamResearchJob::class, function ($job) use ($task) {
            return $job->taskId === $task->id;
        });

        Log::info('[TEST] Test passed - Nova Action simulation works');
    }
}
