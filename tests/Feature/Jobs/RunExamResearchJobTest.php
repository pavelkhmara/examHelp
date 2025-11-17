<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RunExamResearchJob;
use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\ExamResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class RunExamResearchJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_runs_identity_guard_and_logs_and_result(): void
    {
        $exam = Exam::factory()->create();

        Queue::fake();

        $full = [
            'language' => 'English',
            'where' => ['country' => 'PL', 'city' => 'Warsaw', 'modality' => 'online'],
            'target' => ['level' => 'B2'],
            'exam_name' => 'IELTS',
        ];

        $res = $this->postJson("/api/exams/{$exam->id}/research", [
            'user_input' => $full,
        ])->assertStatus(202)->json();

        $taskId = $res['task_id'];

        /** @var ExamResearchService|MockInterface $svc */
        $svc = Mockery::mock(ExamResearchService::class)->makePartial();
        // Важно: runIdentityGuard — реальный, чтобы создать лог и result.identity
        $svc->shouldReceive('runIdentityGuard')->passthru();
        // Важно: runPipeline обязан вернуть array по сигнатуре
        $svc->shouldReceive('runPipeline')->andReturn([]);

        $job = new RunExamResearchJob($taskId);
        $job->handle($svc);

        $this->assertDatabaseHas('generation_logs', [
            'generation_task_id' => $taskId,
            'exam_id' => $exam->id,
            'stage' => 'identity',
        ]);

        $task = GenerationTask::findOrFail($taskId);
        $identity = $task->result['identity'] ?? null;
        $this->assertIsArray($identity);
        $this->assertEquals('certain', $identity['status'] ?? null);
        $this->assertTrue(($identity['hold'] ?? false) === true);
        $this->assertGreaterThanOrEqual(0.9, (float) ($identity['confidence'] ?? 0));
    }

    public function test_job_requests_followups_when_input_incomplete(): void
    {
        $exam = Exam::factory()->create();

        Queue::fake();

        $res = $this->postJson("/api/exams/{$exam->id}/research", [
            'user_input' => ['language' => 'German'], // неполный ввод
        ])->assertStatus(202)->json();

        /** @var ExamResearchService|MockInterface $svc */
        $svc = Mockery::mock(ExamResearchService::class)->makePartial();
        $svc->shouldReceive('runIdentityGuard')->passthru();
        $svc->shouldReceive('runPipeline')->andReturn([]);

        $job = new RunExamResearchJob($res['task_id']);
        $job->handle($svc);

        $task = \App\Models\GenerationTask::findOrFail($res['task_id']);
        $identity = $task->result['identity'] ?? null;

        $this->assertIsArray($identity);
        $this->assertEquals('uncertain', $identity['status'] ?? null);
        $this->assertNotEmpty($identity['need_fields'] ?? []);
        $this->assertNotEmpty($identity['followups'] ?? []);
    }

    public function test_saves_identity_with_candidates_when_low_confidence_pending_confirmation(): void
    {
        $exam = Exam::factory()->create();

        Queue::fake();

        // Create task
        $res = $this->postJson("/api/exams/{$exam->id}/research", [
            'user_input' => [
                'exam_name' => 'Państwowy Certyfikat Języka Polskiego',
                'language' => 'Polish',
                'target' => ['level' => 'C1'],
            ],
        ])->assertStatus(202)->json();

        $taskId = $res['task_id'];

        /** @var ExamResearchService|MockInterface $svc */
        $svc = Mockery::mock(ExamResearchService::class)->makePartial();

        // Mock runIterativeIdentityVerification to return identity with confidence 0.96 and candidates
        $svc->shouldReceive('runIterativeIdentityVerification')
            ->once()
            ->andReturn([
                'status' => 'certain',
                'confidence' => 0.96, // Below 0.97 threshold
                'canonical' => [
                    'name' => 'Państwowy Certyfikat Języka Polskiego C1',
                    'family' => 'Państwowy Certyfikat Języka Polskiego',
                    'variant' => 'C1',
                    'provider' => 'Państwowa Komisja ds. Poświadczania Znajomości Języka Polskiego jako Obcego',
                ],
                'candidates' => [
                    [
                        'name' => 'Państwowy Certyfikat Języka Polskiego C1',
                        'score' => 0.96,
                        'family' => 'Państwowy Certyfikat Języka Polskiego',
                        'provider' => 'Państwowa Komisja ds. Poświadczania Znajomości Języka Polskiego jako Obcego',
                    ],
                ],
                'anchors' => ['Test anchor'],
                'need_fields' => [],
                'needs_confidence_boost' => false,
            ]);

        $svc->shouldReceive('runPipeline')->never(); // Should not reach pipeline

        $job = new RunExamResearchJob($taskId);
        $job->handle($svc);

        // Verify task status is pending_confirmation
        $task = GenerationTask::findOrFail($taskId);
        $this->assertEquals('pending_confirmation', $task->status, 'Task should be in pending_confirmation status');

        // CRITICAL: Verify that result['identity'] is saved with candidates
        $identity = $task->result['identity'] ?? null;
        $this->assertIsArray($identity, 'result[\'identity\'] should exist');
        $this->assertEquals(0.96, $identity['confidence'] ?? 0, 'Confidence should be 0.96');
        $this->assertNotEmpty($identity['candidates'] ?? [], 'Candidates should not be empty');
        $this->assertCount(1, $identity['candidates'], 'Should have 1 candidate');
        $this->assertEquals(
            'Państwowy Certyfikat Języka Polskiego C1',
            $identity['candidates'][0]['name'] ?? '',
            'Candidate name should match'
        );

        // Verify exam research_status
        $exam->refresh();
        $this->assertEquals('queued', $exam->research_status, 'Exam research_status should be queued (waiting for confirmation)');
    }
}
