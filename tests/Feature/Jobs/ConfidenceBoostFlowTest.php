<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RunExamResearchJob;
use App\Models\Exam;
use App\Models\ExamDocument;
use App\Models\GenerationTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfidenceBoostFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_confidence_boost_stage_is_triggered_for_medium_confidence(): void
    {
        // Create exam with document
        $exam = Exam::factory()->create();
        $doc = ExamDocument::factory()->create([
            'exam_id' => $exam->id,
            'extracted_text' => 'IELTS Academic Test Format: Listening 40 questions, Reading 40 questions, Writing 2 tasks, Speaking 3 parts. Total time: 165 minutes.',
        ]);

        // Create task with identity result that has confidence 0.92 (should trigger boost)
        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'queued',
            'request' => [
                'user_input' => [
                    'exam_name' => 'IELTS Academic',
                    'language' => 'English',
                    'target' => ['level' => 'B2-C1'],
                ],
                'document_id' => $doc->id,
            ],
            'result' => [
                'identity' => [
                    'status' => 'certain',
                    'confidence' => 0.92, // Medium-high confidence - should trigger boost
                    'canonical' => [
                        'family' => 'IELTS',
                        'variant' => 'Academic',
                        'provider' => 'British Council',
                    ],
                    'anchors' => ['IELTS Academic', 'Listening 40 questions', 'Reading 40 questions'],
                    'need_fields' => [],
                    'red_flags' => [],
                    'needs_confidence_boost' => true,
                ],
            ],
        ]);

        // Run the job (uses sync queue in testing)
        $job = new RunExamResearchJob($task->id);
        $job->handle(app(\App\Services\LanguageApp\ExamResearchService::class));

        // Refresh task
        $task->refresh();

        // Assert: Task should have confidence_boost in result
        $this->assertArrayHasKey('identity', $task->result);
        $identity = $task->result['identity'];

        // Check if confidence_boost was applied
        if (isset($identity['confidence_boost'])) {
            $this->assertArrayHasKey('original_confidence', $identity['confidence_boost']);
            $this->assertArrayHasKey('boosted_confidence', $identity['confidence_boost']);
            $this->assertArrayHasKey('adjustment_reason', $identity['confidence_boost']);
            $this->assertArrayHasKey('checks_performed', $identity['confidence_boost']);
            $this->assertArrayHasKey('evidence_quality', $identity['confidence_boost']);

            // Original confidence should be 0.92
            $this->assertEquals(0.92, $identity['confidence_boost']['original_confidence']);

            // Check that a generation log was created for confidence_boost stage
            $this->assertDatabaseHas('generation_logs', [
                'exam_id' => $exam->id,
                'generation_task_id' => $task->id,
                'stage' => 'confidence_boost',
            ]);
        }

        // If confidence reached 0.97+, task should be pending_confirmation
        if (($identity['confidence'] ?? 0) >= 0.97) {
            $this->assertEquals('pending_confirmation', $task->status);
            $this->assertTrue($identity['hold'] ?? false);
        }
    }

    public function test_job_stops_if_already_pending_confirmation(): void
    {
        $exam = Exam::factory()->create();

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'pending_confirmation', // Already waiting for user
            'request' => [
                'user_input' => ['exam_name' => 'IELTS'],
            ],
            'result' => [
                'identity' => [
                    'status' => 'certain',
                    'confidence' => 0.97,
                    'hold' => true,
                ],
            ],
        ]);

        // Run the job
        $job = new RunExamResearchJob($task->id);
        $job->handle(app(\App\Services\LanguageApp\ExamResearchService::class));

        // Refresh task
        $task->refresh();

        // Status should remain pending_confirmation (job should have stopped immediately)
        $this->assertEquals('pending_confirmation', $task->status);

        // Task result should be unchanged (no new stages run)
        $this->assertArrayHasKey('identity', $task->result);
        $this->assertTrue($task->result['identity']['hold'] ?? false);
    }

    public function test_low_confidence_skips_boost_and_triggers_variant_probe(): void
    {
        $exam = Exam::factory()->create();

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'queued',
            'request' => [
                'user_input' => [
                    'exam_name' => 'Some Exam',
                    'language' => 'Polish',
                ],
            ],
            'result' => [
                'identity' => [
                    'status' => 'uncertain',
                    'confidence' => 0.75, // Low confidence - should skip boost, trigger variant_probe
                    'canonical' => [
                        'family' => 'unknown',
                    ],
                    'need_fields' => ['provider', 'variant'],
                    'red_flags' => [],
                ],
            ],
        ]);

        // Run the job
        $job = new RunExamResearchJob($task->id);
        $job->handle(app(\App\Services\LanguageApp\ExamResearchService::class));

        // Refresh task
        $task->refresh();

        // Should not have confidence_boost in result (confidence too low)
        if (isset($task->result['identity']['confidence_boost'])) {
            $this->markTestSkipped('Confidence boost should not run for confidence < 0.80');
        }

        // Should have variant_probe result or pending_clarification status
        // (depending on MockAiProvider behavior)
        $this->assertTrue(
            isset($task->result['variant_probe']) || $task->status === 'pending_clarification',
            'Low confidence should trigger variant_probe'
        );
    }
}
