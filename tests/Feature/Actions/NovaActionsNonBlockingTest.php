<?php

namespace Tests\Feature\Actions;

use App\Jobs\RunConfidenceBoostJob;
use App\Jobs\RunPhaseAJob;
use App\Jobs\RunPhaseBJob;
use App\Models\Exam;
use App\Models\GenerationTask;
use App\Nova\Actions\ConfidenceBoostAction;
use App\Nova\Actions\RunOverviewPhaseAAction;
use App\Nova\Actions\RunOverviewPhaseBAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Fields\ActionFields;
use Tests\TestCase;

class NovaActionsNonBlockingTest extends TestCase
{
    use RefreshDatabase;

    public function test_confidence_boost_action_returns_instantly(): void
    {
        $exam = Exam::factory()->create();

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'pending_confirmation',
            'request' => [],
            'result' => [
                'identity' => [
                    'confidence' => 0.85,
                    'status' => 'certain',
                ],
            ],
        ]);

        Queue::fake();

        $action = new ConfidenceBoostAction();
        $fields = new ActionFields(collect([]), collect([]));
        $models = new Collection([$exam]);

        $start = microtime(true);

        $result = $action->handle($fields, $models);

        $duration = microtime(true) - $start;

        // Action should return instantly (< 100ms)
        $this->assertLessThan(0.1, $duration, 'ConfidenceBoostAction should return instantly');

        // Job should be dispatched
        Queue::assertPushed(RunConfidenceBoostJob::class);

        // Action should return message
        $this->assertStringContainsString('queued', $result->message);
    }

    public function test_phase_a_action_returns_instantly(): void
    {
        $exam = Exam::factory()->create();

        Queue::fake();

        $action = new RunOverviewPhaseAAction();
        $fields = new ActionFields(collect([]), collect([]));
        $models = new Collection([$exam]);

        $start = microtime(true);

        $result = $action->handle($fields, $models);

        $duration = microtime(true) - $start;

        // Action should return instantly (< 100ms)
        $this->assertLessThan(0.1, $duration, 'RunOverviewPhaseAAction should return instantly');

        // Job should be dispatched
        Queue::assertPushed(RunPhaseAJob::class);

        // Action should return message
        $this->assertStringContainsString('queued', $result->message);
    }

    public function test_phase_b_action_returns_instantly(): void
    {
        $exam = Exam::factory()->create([
            'structure_v2' => ['categories' => []], // Phase A completed
        ]);

        Queue::fake();

        $action = new RunOverviewPhaseBAction();
        $fields = new ActionFields(collect([]), collect([]));
        $models = new Collection([$exam]);

        $start = microtime(true);

        $result = $action->handle($fields, $models);

        $duration = microtime(true) - $start;

        // Action should return instantly (< 100ms)
        $this->assertLessThan(0.1, $duration, 'RunOverviewPhaseBAction should return instantly');

        // Job should be dispatched
        Queue::assertPushed(RunPhaseBJob::class);

        // Action should return message
        $this->assertStringContainsString('queued', $result->message);
    }

    public function test_multiple_actions_can_be_triggered_without_blocking(): void
    {
        $exam1 = Exam::factory()->create(['structure_v2' => []]);
        $exam2 = Exam::factory()->create(['structure_v2' => []]);
        $exam3 = Exam::factory()->create(['structure_v2' => []]);

        Queue::fake();

        $start = microtime(true);

        // Trigger 3 Phase A actions
        $action = new RunOverviewPhaseAAction();
        $fields = new ActionFields(collect([]), collect([]));

        $action->handle($fields, new Collection([$exam1]));
        $action->handle($fields, new Collection([$exam2]));
        $action->handle($fields, new Collection([$exam3]));

        $duration = microtime(true) - $start;

        // All 3 actions should complete quickly (< 300ms)
        $this->assertLessThan(0.3, $duration, 'Multiple actions should not block each other');

        // 3 jobs should be dispatched
        Queue::assertPushed(RunPhaseAJob::class, 3);
    }

    public function test_action_creates_task_immediately(): void
    {
        $exam = Exam::factory()->create();

        Queue::fake();

        $action = new RunOverviewPhaseAAction();
        $fields = new ActionFields(collect([]), collect([]));
        $models = new Collection([$exam]);

        $taskCountBefore = GenerationTask::count();

        $action->handle($fields, $models);

        $taskCountAfter = GenerationTask::count();

        // Task should be created immediately
        $this->assertEquals($taskCountBefore + 1, $taskCountAfter);

        // Task should be queued
        $task = GenerationTask::latest('id')->first();
        $this->assertEquals('queued', $task->status);
        $this->assertEquals('research_phase_a', $task->type);
    }

    public function test_action_adds_activity_log_immediately(): void
    {
        $exam = Exam::factory()->create();

        Queue::fake();

        $action = new RunOverviewPhaseAAction();
        $fields = new ActionFields(collect([]), collect([]));
        $models = new Collection([$exam]);

        $action->handle($fields, $models);

        $task = GenerationTask::latest('id')->first();

        // Activity should be logged
        $this->assertNotEmpty($task->activities);
        $this->assertStringContainsString('queued', json_encode($task->activities));
    }

    public function test_confidence_boost_action_validates_before_queueing(): void
    {
        $exam = Exam::factory()->create();

        // No active task
        Queue::fake();

        $action = new ConfidenceBoostAction();
        $fields = new ActionFields(collect([]), collect([]));
        $models = new Collection([$exam]);

        $result = $action->handle($fields, $models);

        // Should return error without queueing
        $this->assertStringContainsString('No active task', $result->message);

        // No job should be dispatched
        Queue::assertNotPushed(RunConfidenceBoostJob::class);
    }

    public function test_phase_b_action_validates_phase_a_completed(): void
    {
        $exam = Exam::factory()->create([
            'structure_v2' => null, // Phase A not completed
        ]);

        Queue::fake();

        $action = new RunOverviewPhaseBAction();
        $fields = new ActionFields(collect([]), collect([]));
        $models = new Collection([$exam]);

        $result = $action->handle($fields, $models);

        // Should return error
        $this->assertStringContainsString('Phase A', $result->message);

        // No job should be dispatched
        Queue::assertNotPushed(RunPhaseBJob::class);
    }
}
