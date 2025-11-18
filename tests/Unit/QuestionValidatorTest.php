<?php

namespace Tests\Unit;

use App\Models\Exam;
use App\Models\GenerationPlan;
use App\Services\LanguageApp\QuestionValidator;
use App\Services\LanguageApp\Validators\JsonSchemaQuestionV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_validate_and_finalize_assigns_id_and_enriches_metadata(): void
    {
        $exam = Exam::factory()->create([
            'meta' => ['structure_v2' => ['sections' => []]],
        ]);

        $plan = GenerationPlan::factory()->create([
            'exam_id' => $exam->id,
            'section_id' => 'sec-reading',
            'assembly_mode' => 'inline',
            'plan_data' => ['placeholders' => [['id' => 'slot1', 'type' => 'single_select']]],
            'status' => 'completed',
            'total_questions' => 1,
        ]);

        $validator = new QuestionValidator(new JsonSchemaQuestionV2());

        $questions = [[
            // intentionally missing 'id'
            'version' => '2.0',
            'type' => 'single_select',
            'skills_measured' => ['reading'],
            'time_limit_sec' => 60,
            'instructions' => ['brief' => 'Choose', 'full' => 'Choose best answer'],
            'stimulus' => ['text_html' => '<p>Text</p>'],
            'interaction' => [
                'response_type' => 'selection',
                'options' => [
                    ['id' => 'A', 'label' => 'A'],
                    ['id' => 'B', 'label' => 'B'],
                ],
            ],
            'response' => ['mode' => 'selection'],
            'scoring' => ['method' => 'keyed', 'answer_key' => ['A'], 'max_score' => 1],
            'metadata' => ['cefr' => ['B1'], 'difficulty' => 'easy', 'topic' => 't', 'language' => 'en'],
        ]];

        $finalized = $validator->validateAndFinalize($questions, $plan, $exam);

        $this->assertCount(1, $finalized);
        $this->assertArrayHasKey('id', $finalized[0]);
        $this->assertSame('sec-reading', $finalized[0]['section_id']);
        $this->assertSame('inline', $finalized[0]['assembly_mode']);
        $this->assertFalse($finalized[0]['is_duplicate']);
    }
}


