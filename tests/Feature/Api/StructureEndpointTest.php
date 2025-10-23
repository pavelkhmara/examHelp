<?php

namespace Tests\Feature\Api;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\ExamExampleQuestion;
use App\Models\GenerationTask;
use App\Services\LanguageApp\Validators\QuestionTypeContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StructureEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_structure_returns_404_when_not_ready(): void
    {
        $exam = Exam::factory()->create([
            'research_status' => 'queued',
        ]);

        $this->getJson("/api/exams/{$exam->id}/structure")
            ->assertStatus(404)
            ->assertJson([
                'code' => 'structure_not_ready',
            ]);
    }

    public function test_structure_returns_aggregate_without_nulls(): void
    {
        $exam = Exam::factory()->create([
            'research_status' => 'completed',
            'meta' => [
                'exam_structure' => [
                    'total_score' => ['min' => 0, 'max' => 60],
                ],
            ],
        ]);

        // readiness gate
        GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'completed',
            'request' => [],
            'response' => [],
            'result' => ['sources' => [
                ['title' => 'Official spec', 'url' => 'https://example.com', 'publisher' => 'MinEdu'],
            ]],
            'attempts' => 1,
        ]);

        $cat = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'key' => 'reading',
            'name' => 'Reading',
            'meta' => ['score_range' => ['min' => 0, 'max' => 30]],
            'order' => 1,
        ]);

        // Создаем пример через фабрику БЕЗ ручного payload — фабрика генерит валидный по типу payload
        ExamExampleQuestion::factory()->create([
            'exam_id' => $exam->id,
            'exam_category_id' => $cat->id,
            // если фабрика сама выставляет тип — можно не указывать; если нужно — укажите допустимый тип
            'type' => 'single_select',
        ]);

        $res = $this->getJson("/api/exams/{$exam->id}/structure")
            ->assertStatus(200)
            ->assertJsonStructure([
                'exam' => ['id', 'slug', 'title', 'level', 'research_status'],
                'sections' => [[
                    'id', 'key', 'title', 'description', 'order',
                    'score_range' => ['min', 'max'],
                    'scoring_methodology',
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
                'sources',
            ])
            ->json();

        // Проверяем, что нет null в ответе
        $that = $this;
        $it = function ($v) use (&$it, $that) {
            if (is_array($v)) {
                foreach ($v as $vv) {
                    $it($vv);
                }

                return;
            }
            $that->assertNotNull($v);
        };
        $it($res);
    }

    public function test_structure_examples_are_whitelisted_types(): void
    {
        $exam = Exam::factory()->create(['research_status' => 'completed']);

        GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'completed',
            'request' => [], 'response' => [], 'result' => [], 'attempts' => 1,
        ]);

        $cat = ExamCategory::factory()->create([
            'exam_id' => $exam->id, 'key' => 'listening', 'name' => 'Listening',
            'meta' => ['score_range' => ['min' => 0, 'max' => 30]], 'order' => 2,
        ]);

        // Не задаём payload вручную — фабрика создаёт валидный payload под выбранный тип
        ExamExampleQuestion::factory()->create([
            'exam_id' => $exam->id,
            'exam_category_id' => $cat->id,
            'type' => 'short_answer', // допустимый тип; фабрика должна знать, как заполнить payload
        ]);

        $json = $this->getJson("/api/exams/{$exam->id}/structure")->assertOk()->json();

        $allTypes = collect($json['sections'])
            ->flatMap(fn ($s) => collect($s['examples'])->pluck('type'))
            ->unique()
            ->values()
            ->all();

        foreach ($allTypes as $t) {
            $this->assertTrue(
                QuestionTypeContract::isKnownType($t),
                "Type [$t] must be in whitelist"
            );
        }
    }
}
