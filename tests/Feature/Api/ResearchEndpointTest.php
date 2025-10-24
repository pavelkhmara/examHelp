<?php

namespace Tests\Feature\Api;

use App\Models\Exam;
use App\Models\ExamDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ResearchEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_research_accepts_full_user_input_and_queues_task(): void
    {
        $exam = Exam::factory()->create();

        // Не даём джобе выполниться немедленно (иначе статус станет completed)
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

        $this->assertArrayHasKey('task_id', $res);
        $this->assertDatabaseHas('generation_tasks', [
            'id' => $res['task_id'],
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'queued', // ожидаем queued, т.к. очередь зафейкана
        ]);
    }

    public function test_research_accepts_incomplete_and_still_queues_with_document_id(): void
    {
        $exam = Exam::factory()->create();
        $doc = ExamDocument::factory()->create(['exam_id' => $exam->id]);

        Queue::fake();

        $incomplete = [
            'language' => 'German',
            // нет where/country/modality и target
        ];

        $res = $this->postJson("/api/exams/{$exam->id}/research", [
            'user_input' => $incomplete,
            'document_id' => $doc->id,
        ])->assertStatus(202)->json();

        $this->assertArrayHasKey('task_id', $res);
        $this->assertDatabaseHas('generation_tasks', [
            'id' => $res['task_id'],
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'queued',
        ]);
    }
}
