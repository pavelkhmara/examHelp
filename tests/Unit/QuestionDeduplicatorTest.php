<?php

namespace Tests\Unit;

use App\Models\Exam;
use App\Services\LanguageApp\QuestionDeduplicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionDeduplicatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_detects_duplicate_against_existing_meta(): void
    {
        $exam = Exam::factory()->create([
            'meta' => [
                'generated_questions_v2' => [
                    [
                        'id' => 'q-existing',
                        'type' => 'single_select',
                        'instructions' => ['brief' => 'Choose', 'full' => 'Choose one'],
                        'stimulus' => ['text_html' => '<p>Hello World passage.</p>'],
                        'interaction' => ['response_type' => 'selection', 'options' => [['id' => 'A', 'label' => 'A']]],
                        'response' => ['mode' => 'selection'],
                        'scoring' => ['method' => 'keyed', 'answer_key' => ['A']],
                        'metadata' => ['language' => 'en'],
                    ],
                ],
            ],
        ]);

        $new = [[
            'id' => 'q-new',
            'type' => 'single_select',
            'instructions' => ['brief' => 'Choose', 'full' => 'Choose one'],
            'stimulus' => ['text_html' => '<p>Hello World passage! </p>'], // similar text
            'interaction' => ['response_type' => 'selection', 'options' => [['id' => 'A', 'label' => 'A']]],
            'response' => ['mode' => 'selection'],
            'scoring' => ['method' => 'keyed', 'answer_key' => ['A']],
            'metadata' => ['language' => 'en'],
        ]];

        $deduplicator = new QuestionDeduplicator;
        $result = $deduplicator->detectDuplicates($new, $exam);

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['is_duplicate']);
        $this->assertSame('q-existing', $result[0]['duplicate_of']);
        $this->assertGreaterThan(0.85, $result[0]['similarity_score']);
    }
}
