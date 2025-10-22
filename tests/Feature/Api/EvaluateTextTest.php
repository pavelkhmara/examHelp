<?php

namespace Tests\Feature\Api;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\ExamExampleQuestion;
use App\Models\GenerationTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvaluateTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_text_evaluation_returns_score_and_rubric(): void
    {
        $exam = Exam::factory()->create(['is_active' => true]);
        $cat  = ExamCategory::factory()->create(['exam_id' => $exam->id]);

        $ex = ExamExampleQuestion::factory()->create([
            'exam_id'          => $exam->id,
            'exam_category_id' => $cat->id,
        ]);

        $resp = $this->postJson('/api/evaluate/text', [
            'exam_id'     => $exam->id,
            'category_id' => $cat->id,
            'question_id' => $ex->id,
            'answer_text' => 'I usually wake up at seven and go to work by bus.',
        ])->assertOk()->json();

        $this->assertTrue($resp['ok']);
        $this->assertIsInt($resp['score']);
        $this->assertGreaterThanOrEqual(0, $resp['score']);
        $this->assertLessThanOrEqual(100, $resp['score']);
        $this->assertIsArray($resp['rubric_breakdown']);
        $this->assertArrayHasKey('content', $resp['rubric_breakdown']);
        $this->assertArrayHasKey('clarity', $resp['rubric_breakdown']);
        $this->assertArrayHasKey('language', $resp['rubric_breakdown']);
    }

    public function test_async_enqueues_generation_task(): void
    {
        $exam = Exam::factory()->create(['is_active' => true]);

        $resp = $this->postJson('/api/evaluate/text', [
            'exam_id'     => $exam->id,
            'answer_text' => 'Short text',
            'async'       => true,
        ])->assertStatus(202)->json();

        $this->assertTrue($resp['ok']);
        $this->assertTrue($resp['queued']);
        $this->assertNotEmpty($resp['task_id']);

        $this->assertDatabaseHas('generation_tasks', [
            'id'     => $resp['task_id'],
            'type'   => 'evaluate_text',
            'status' => 'queued',
        ]);
    }
}
