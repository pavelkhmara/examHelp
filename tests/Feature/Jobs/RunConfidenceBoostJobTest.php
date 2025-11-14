<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RunConfidenceBoostJob;
use App\Jobs\RunExamResearchJob;
use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\ExamResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class RunConfidenceBoostJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_confidence_boost_job_runs_successfully(): void
    {
        $exam = Exam::factory()->create([
            'title' => 'Test Exam for Confidence Boost',
            'user_input' => 'IELTS Academic Test',
            'level' => 'B2',
        ]);

        // Create task with identity data that needs boost (confidence 0.85)
        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'confidence_boost',
            'status' => 'queued',
            'request' => ['triggered_by' => 'test'],
            'result' => [
                'identity' => [
                    'confidence' => 0.85,
                    'status' => 'certain',
                    'exam_name' => 'IELTS',
                ],
            ],
        ]);

        /** @var ExamResearchService|MockInterface $svc */
        $svc = Mockery::mock(ExamResearchService::class)->makePartial();

        // Mock runConfidenceBoost to return boosted identity
        $svc->shouldReceive('runConfidenceBoost')
            ->once()
            ->andReturn([
                'confidence' => 0.98, // Boosted above 0.97
                'confidence_boosted_at' => now()->toISOString(),
                'status' => 'certain',
                'exam_name' => 'IELTS',
            ]);

        Queue::fake();

        $job = new RunConfidenceBoostJob($task->id);
        $job->handle($svc);

        // Check task was updated
        $task->refresh();
        $this->assertEquals('queued', $task->status, 'Task should be queued for next stage');
        $this->assertGreaterThanOrEqual(0.97, $task->result['identity']['confidence'] ?? 0);
        $this->assertNotNull($task->result['identity']['confidence_boosted_at'] ?? null);

        // Check that RunExamResearchJob was dispatched to continue pipeline
        Queue::assertPushed(RunExamResearchJob::class);
    }

    public function test_confidence_boost_job_sets_pending_when_still_low(): void
    {
        $exam = Exam::factory()->create();

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'confidence_boost',
            'status' => 'queued',
            'request' => [],
            'result' => [
                'identity' => [
                    'confidence' => 0.85,
                    'status' => 'certain',
                ],
            ],
        ]);

        /** @var ExamResearchService|MockInterface $svc */
        $svc = Mockery::mock(ExamResearchService::class)->makePartial();

        // Mock boost that doesn't reach threshold
        $svc->shouldReceive('runConfidenceBoost')
            ->once()
            ->andReturn([
                'confidence' => 0.90, // Still below 0.97
                'confidence_boosted_at' => now()->toISOString(),
                'status' => 'certain',
            ]);

        $job = new RunConfidenceBoostJob($task->id);
        $job->handle($svc);

        $task->refresh();
        $this->assertEquals('pending_confirmation', $task->status);
        $this->assertLessThan(0.97, $task->result['identity']['confidence'] ?? 0);
    }

    public function test_confidence_boost_job_skips_if_already_high(): void
    {
        $exam = Exam::factory()->create();

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'confidence_boost',
            'status' => 'queued',
            'request' => [],
            'result' => [
                'identity' => [
                    'confidence' => 0.99, // Already high
                    'status' => 'certain',
                ],
            ],
        ]);

        /** @var ExamResearchService|MockInterface $svc */
        $svc = Mockery::mock(ExamResearchService::class)->makePartial();

        // Should NOT call runConfidenceBoost
        $svc->shouldNotReceive('runConfidenceBoost');

        Queue::fake();

        $job = new RunConfidenceBoostJob($task->id);
        $job->handle($svc);

        $task->refresh();
        $this->assertEquals('queued', $task->status);

        // Should dispatch next job
        Queue::assertPushed(RunExamResearchJob::class);
    }

    public function test_confidence_boost_job_handles_errors_gracefully(): void
    {
        $exam = Exam::factory()->create();

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'confidence_boost',
            'status' => 'queued',
            'request' => [],
            'result' => [
                'identity' => [
                    'confidence' => 0.85,
                    'status' => 'certain',
                ],
            ],
        ]);

        /** @var ExamResearchService|MockInterface $svc */
        $svc = Mockery::mock(ExamResearchService::class)->makePartial();

        // Simulate error
        $svc->shouldReceive('runConfidenceBoost')
            ->once()
            ->andThrow(new \Exception('AI service unavailable'));

        $job = new RunConfidenceBoostJob($task->id);

        $this->expectException(\Exception::class);
        $job->handle($svc);

        $task->refresh();
        $this->assertEquals('failed', $task->status);
        $this->assertStringContainsString('AI service unavailable', $task->error ?? '');
    }

    public function test_confidence_boost_job_execution_time_is_fast(): void
    {
        $exam = Exam::factory()->create();

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'confidence_boost',
            'status' => 'queued',
            'request' => [],
            'result' => [
                'identity' => [
                    'confidence' => 0.85,
                    'status' => 'certain',
                ],
            ],
        ]);

        /** @var ExamResearchService|MockInterface $svc */
        $svc = Mockery::mock(ExamResearchService::class)->makePartial();
        $svc->shouldReceive('runConfidenceBoost')
            ->once()
            ->andReturn([
                'confidence' => 0.98,
                'confidence_boosted_at' => now()->toISOString(),
            ]);

        Queue::fake();

        $start = microtime(true);

        $job = new RunConfidenceBoostJob($task->id);
        $job->handle($svc);

        $duration = microtime(true) - $start;

        // Job dispatch should be fast (< 1 second with mocked service)
        $this->assertLessThan(1.0, $duration, 'Job execution should be fast when dispatching');
    }
}
