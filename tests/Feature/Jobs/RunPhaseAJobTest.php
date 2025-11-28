<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RunPhaseAJob;
use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\ExamResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * @group broken
 */
class RunPhaseAJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_a_job_runs_successfully(): void
    {
        $exam = Exam::factory()->create([
            'title' => 'Test Exam for Phase A',
            'user_input' => 'IELTS Academic Test',
            'level' => 'B2',
        ]);

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research_phase_a',
            'status' => 'queued',
            'request' => [],
        ]);

        $mockResult = [
            'categories' => [
                [
                    'name' => 'Listening',
                    'archetypes' => [
                        ['task_type' => 'multiple_choice', 'count' => 10],
                    ],
                ],
            ],
        ];

        /** @var ExamResearchService|MockInterface $svc */
        $svc = Mockery::mock(ExamResearchService::class);
        $svc->shouldReceive('runPhaseA')
            ->once()
            ->with(
                Mockery::on(fn ($e) => $e->id === $exam->id),
                Mockery::on(fn ($t) => $t->id === $task->id)
            )
            ->andReturn($mockResult);

        $job = new RunPhaseAJob($task->id);
        $job->handle($svc);

        // Check task completed
        $task->refresh();
        $this->assertEquals('completed', $task->status);
        $this->assertIsArray($task->result);
        $this->assertArrayHasKey('categories', $task->result);

        // Check exam updated
        $exam->refresh();
        $this->assertEquals('phase_a_completed', $exam->research_status);
        $this->assertIsArray($exam->structure_v2);
        $this->assertArrayHasKey('categories', $exam->structure_v2);
    }

    public function test_phase_a_job_handles_errors(): void
    {
        $exam = Exam::factory()->create();

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research_phase_a',
            'status' => 'queued',
            'request' => [],
        ]);

        /** @var ExamResearchService|MockInterface $svc */
        $svc = Mockery::mock(ExamResearchService::class);
        $svc->shouldReceive('runPhaseA')
            ->once()
            ->andThrow(new \Exception('AI service error'));

        $job = new RunPhaseAJob($task->id);

        $this->expectException(\Exception::class);
        $job->handle($svc);

        $task->refresh();
        $this->assertEquals('failed', $task->status);
        $this->assertStringContainsString('AI service error', $task->error ?? '');

        $exam->refresh();
        $this->assertEquals('failed', $exam->research_status);
    }

    public function test_phase_a_job_prevents_duplicate_execution(): void
    {
        $exam = Exam::factory()->create();

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research_phase_a',
            'status' => 'completed', // Already completed
            'request' => [],
            'result' => ['categories' => []],
        ]);

        /** @var ExamResearchService|MockInterface $svc */
        $svc = Mockery::mock(ExamResearchService::class);

        // Should NOT call runPhaseA for completed task
        $svc->shouldNotReceive('runPhaseA');

        $job = new RunPhaseAJob($task->id);
        $job->handle($svc);

        // Status should remain completed
        $task->refresh();
        $this->assertEquals('completed', $task->status);
    }

    public function test_phase_a_job_execution_is_non_blocking(): void
    {
        $exam = Exam::factory()->create();

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research_phase_a',
            'status' => 'queued',
            'request' => [],
        ]);

        /** @var ExamResearchService|MockInterface $svc */
        $svc = Mockery::mock(ExamResearchService::class);
        $svc->shouldReceive('runPhaseA')
            ->once()
            ->andReturn(['categories' => []]);

        $start = microtime(true);

        $job = new RunPhaseAJob($task->id);
        $job->handle($svc);

        $duration = microtime(true) - $start;

        // Job should execute quickly with mocked service
        $this->assertLessThan(1.0, $duration, 'Phase A job should not block');
    }

    public function test_phase_a_job_updates_heartbeat(): void
    {
        $exam = Exam::factory()->create();

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research_phase_a',
            'status' => 'queued',
            'request' => [],
        ]);

        $initialHeartbeat = $task->heartbeat_at;

        /** @var ExamResearchService|MockInterface $svc */
        $svc = Mockery::mock(ExamResearchService::class);
        $svc->shouldReceive('runPhaseA')
            ->once()
            ->andReturn(['categories' => []]);

        sleep(1); // Ensure time passes

        $job = new RunPhaseAJob($task->id);
        $job->handle($svc);

        $task->refresh();
        $this->assertNotEquals($initialHeartbeat, $task->heartbeat_at, 'Heartbeat should be updated');
    }
}
