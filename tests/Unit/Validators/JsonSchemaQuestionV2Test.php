<?php

namespace Tests\Unit\Validators;

use App\Services\LanguageApp\Validators\JsonSchemaQuestionV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JsonSchemaQuestionV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_valid_question_passes_validation(): void
    {
        $validator = new JsonSchemaQuestionV2();

        $q = [
            'id' => 'q1',
            'version' => '2.0',
            'type' => 'single_select',
            'skills_measured' => ['reading'],
            'time_limit_sec' => 90,
            'instructions' => ['brief' => 'Choose', 'full' => 'Choose best answer'],
            'stimulus' => ['text_html' => '<p>Passage</p>'],
            'interaction' => [
                'response_type' => 'selection',
                'options' => [
                    ['id' => 'A', 'label' => 'Option A'],
                    ['id' => 'B', 'label' => 'Option B'],
                ],
            ],
            'response' => ['mode' => 'selection'],
            'scoring' => ['method' => 'keyed', 'answer_key' => ['A'], 'max_score' => 1],
            'metadata' => ['cefr' => ['B1'], 'difficulty' => 'medium', 'topic' => 'test', 'language' => 'en'],
        ];

        $validated = $validator->validate($q);

        $this->assertIsArray($validated);
        $this->assertSame('single_select', $validated['type']);
    }
}


