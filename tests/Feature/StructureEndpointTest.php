<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\ExamExampleQuestion;
use App\Models\GenerationTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StructureEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_404_when_structure_absent(): void
    {
        $exam = Exam::factory()->create(['research_status' => 'queued']);
        $this->getJson("/api/exams/{$exam->id}/structure")
            ->assertStatus(404)
            ->assertJson(['code' => 'structure_not_ready']);
    }

    public function test_returns_structure_when_task_completed(): void
    {
        $exam = Exam::factory()->create(['research_status' => 'completed']);

        GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'completed',
            'request' => [], 'response' => [],
            'result' => ['sources' => [
                ['title' => 'Spec', 'url' => 'https://example.com', 'publisher' => 'MinEdu'],
            ]],
            'attempts' => 1,
        ]);

        $cat = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'key' => 'use_of_english',
            'name' => 'Use of English',
            'meta' => ['score_range' => ['min' => 0, 'max' => 20]],
            'order' => 3,
        ]);

        // Пример через фабрику — валидный payload по типу
        ExamExampleQuestion::factory()->create([
            'exam_id' => $exam->id,
            'exam_category_id' => $cat->id,
            'type' => 'true_false',
        ]);

        $this->getJson("/api/exams/{$exam->id}/structure")
            ->assertOk()
            ->assertJsonStructure([
                'exam' => ['id', 'slug', 'title', 'research_status'],
                'sources' => [['url', 'title', 'publisher']],
                // новое API T7: вместо 'archetypes' — секции/категории
                'sections' => [[
                    'id', 'key', 'title', 'description', 'order',
                    'score_range' => ['min', 'max'],
                    'examples' => [[
                        'id', 'type', 'question', 'payload',
                        'model_answers' => ['good', 'average', 'bad'],
                        'rubric_breakdown',
                    ]],
                ]],
                'meta' => [
                    'total_score' => ['min', 'max'],
                    'generated_at', 'task_id',
                ],
            ]);
    }
}
