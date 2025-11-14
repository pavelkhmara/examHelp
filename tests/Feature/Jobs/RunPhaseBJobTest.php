<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RunPhaseBJob;
use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\ExamResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class RunPhaseBJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_b_job_runs_successfully(): void
    {
        $exam = Exam::factory()->create([
            'title' => 'Test Exam for Phase B',
            'user_input' => 'IELTS Academic Test',
            'level' => 'B2',
            'structure_v2' => [
                'categories' => [
                    ['name' => 'Listening', 'archetypes' => []],
                ],
            ],
        ]);

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research_phase_b',
            'status' => 'queued',
            'request' => [],
        ]);

        $mockResult = [
            'categories' => [
                [
                    'name' => 'Listening',
                    'archetypes' => [],
                    'assembly_config' => [
                        'total_questions' => 40,
                        'duration_minutes' => 30,
                    ],
                ],
            ],
        ];

        /** @var ExamResearchService|MockInterface $svc */
        $svc = Mockery::mock(ExamResearchService::class);
        $svc->shouldReceive('runPhaseB')
            ->once()
            ->with($exam, $task, $exam->structure_v2)
            ->andReturn($mockResult);

        $job = new RunPhaseBJob($task->id);
        $job->handle($svc);

        // Check task completed
        $task->refresh();
        $this->assertEquals('completed', $task->status);
        $this->assertIsArray($task->result);

        // Check exam updated with assembly config
        $exam->refresh();
        $this->assertEquals('completed', $exam->research_status);
        $this->assertArrayHasKey('assembly_config', $exam->structure_v2['categories'][0] ?? []);
    }

    public function test_phase_b_job_fails_without_phase_a_structure(): void
    {
        $exam = Exam::factory()->create([
            'structure_v2' => null, // No Phase A result
        ]);

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research_phase_b',
            'status' => 'queued',
            'request' => [],
        ]);

        /** @var ExamResearchService|MockInterface $svc */
        $svc = Mockery::mock(ExamResearchService::class);

        // Should NOT call runPhaseB
        $svc->shouldNotReceive('runPhaseB');

        $job = new RunPhaseBJob($task->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Phase A structure_v2 is required before Phase B');

        $job->handle($svc);

        $task->refresh();
        $this->assertEquals('failed', $task->status);
    }

    public function test_phase_b_job_handles_errors(): void
    {
        $exam = Exam::factory()->create([
            'structure_v2' => ['categories' => []],
        ]);

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research_phase_b',
            'status' => 'queued',
            'request' => [],
        ]);

        /** @var ExamResearchService|MockInterface $svc */
        $svc = Mockery::mock(ExamResearchService::class);
        $svc->shouldReceive('runPhaseB')
            ->once()
            ->andThrow(new \Exception('Assembly generation failed'));

        $job = new RunPhaseBJob($task->id);

        $this->expectException(\Exception::class);
        $job->handle($svc);

        $task->refresh();
        $this->assertEquals('failed', $task->status);
        $this->assertStringContainsString('Assembly generation failed', $task->error ?? '');

        $exam->refresh();
        $this->assertEquals('failed', $exam->research_status);
    }

    public function test_phase_b_job_prevents_duplicate_execution(): void
    {
        $exam = Exam::factory()->create([
            'structure_v2' => ['categories' => []],
        ]);

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research_phase_b',
            'status' => 'completed', // Already completed
            'request' => [],
            'result' => ['categories' => []],
        ]);

        /** @var ExamResearchService|MockInterface $svc */
        $svc = Mockery::mock(ExamResearchService::class);

        // Should NOT call runPhaseB for completed task
        $svc->shouldNotReceive('runPhaseB');

        $job = new RunPhaseBJob($task->id);
        $job->handle($svc);

        // Status should remain completed
        $task->refresh();
        $this->assertEquals('completed', $task->status);
    }

    public function test_phase_b_job_execution_is_non_blocking(): void
    {
        $exam = Exam::factory()->create([
            'structure_v2' => ['categories' => []],
        ]);

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research_phase_b',
            'status' => 'queued',
            'request' => [],
        ]);

        /** @var ExamResearchService|MockInterface $svc */
        $svc = Mockery::mock(ExamResearchService::class);
        $svc->shouldReceive('runPhaseB')
            ->once()
            ->andReturn(['categories' => []]);

        $start = microtime(true);

        $job = new RunPhaseBJob($task->id);
        $job->handle($svc);

        $duration = microtime(true) - $start;

        // Job should execute quickly with mocked service
        $this->assertLessThan(1.0, $duration, 'Phase B job should not block');
    }
}
