<?php

namespace Tests\Feature\QuestionTypes;

use App\Models\Exam;
use App\Models\Question;
use App\Services\LanguageApp\QuestionAudioProcessor;
use App\Services\LanguageApp\Validators\QuestionTypeContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for new question types added 2025-11-26
 *
 * Tests:
 * - listen_true_false
 * - listen_yes_no_ng
 * - translation
 * - roleplay
 * - note_completion
 *
 * @group broken
 */
class NewQuestionTypesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that all new types are recognized as valid
     */
    public function test_new_types_are_recognized(): void
    {
        $newTypes = [
            'listen_true_false',
            'listen_yes_no_ng',
            'translation',
            'roleplay',
            'note_completion',
        ];

        foreach ($newTypes as $type) {
            $this->assertTrue(
                QuestionTypeContract::isKnownType($type),
                "Type '{$type}' should be recognized as valid question type"
            );
        }
    }

    /**
     * Test listen_true_false type
     */
    public function test_listen_true_false_has_scoring_rules(): void
    {
        $contract = new QuestionTypeContract;

        $task = [
            'type' => 'listen_true_false',
            'items' => [
                [
                    'id' => 'q1',
                    'prompt' => 'Is this statement true?',
                    'options' => [
                        ['id' => 'true', 'label' => 'True'],
                        ['id' => 'false', 'label' => 'False'],
                    ],
                ],
            ],
            'scoring' => [
                'mode' => 'exact',
                'answer_key' => ['true'],
            ],
        ];

        // Should not throw exception
        $validated = $contract->validateTask($task);

        $this->assertEquals('listen_true_false', $validated['type']);
    }

    /**
     * Test listen_true_false requires audio
     */
    public function test_listen_true_false_requires_audio(): void
    {
        $processor = app(QuestionAudioProcessor::class);

        $question = Question::factory()->create([
            'type' => 'listen_true_false',
            'stimulus' => ['text_html' => 'The sky is blue.'],
            'audio_file_path' => null,
            'instructions' => [
                'brief' => 'Listen and decide',
                'full' => 'Listen to the statement and decide if it is true or false.',
            ],
        ]);

        // Use reflection to access protected method
        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('shouldGenerateAudio');
        $method->setAccessible(true);

        $shouldGenerate = $method->invoke($processor, $question);

        $this->assertTrue(
            $shouldGenerate,
            'listen_true_false should require audio generation'
        );
    }

    /**
     * Test listen_yes_no_ng type
     */
    public function test_listen_yes_no_ng_is_supported(): void
    {
        $this->assertTrue(
            QuestionTypeContract::isKnownType('listen_yes_no_ng'),
            'listen_yes_no_ng should be recognized'
        );

        $exam = Exam::factory()->create();

        $question = Question::factory()->create([
            'exam_id' => $exam->id,
            'type' => 'listen_yes_no_ng',
            'interaction' => [
                'response_type' => 'selection',
                'options' => [
                    ['id' => 'yes', 'label' => 'Yes'],
                    ['id' => 'no', 'label' => 'No'],
                    ['id' => 'ng', 'label' => 'Not Given'],
                ],
            ],
        ]);

        $this->assertEquals('listen_yes_no_ng', $question->type);
    }

    /**
     * Test translation type has scoring rules
     */
    public function test_translation_has_scoring_rules(): void
    {
        $contract = new QuestionTypeContract;

        $task = [
            'type' => 'translation',
            'items' => [
                [
                    'id' => 'q1',
                    'prompt' => 'Translate this text to English',
                ],
            ],
            'scoring' => [
                'mode' => 'rubric',
                'rubric' => [
                    'criteria' => [
                        [
                            'name' => 'Accuracy',
                            'weight' => 0.5,
                            'levels' => [
                                ['score' => 0, 'description' => 'Poor'],
                                ['score' => 5, 'description' => 'Excellent'],
                            ],
                        ],
                    ],
                ],
                'max_score' => 5,
            ],
        ];

        // Should not throw exception
        $validated = $contract->validateTask($task);

        $this->assertEquals('translation', $validated['type']);
    }

    /**
     * Test translation does NOT require audio
     */
    public function test_translation_does_not_require_audio(): void
    {
        $processor = app(QuestionAudioProcessor::class);

        $question = Question::factory()->create([
            'type' => 'translation',
            'stimulus' => ['text_html' => 'Translate this text.'],
            'audio_file_path' => null,
        ]);

        // Use reflection to access protected method
        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('shouldGenerateAudio');
        $method->setAccessible(true);

        $shouldGenerate = $method->invoke($processor, $question);

        $this->assertFalse(
            $shouldGenerate,
            'translation should NOT require audio generation (user translates text)'
        );
    }

    /**
     * Test roleplay type
     */
    public function test_roleplay_has_scoring_rules(): void
    {
        $contract = new QuestionTypeContract;

        $task = [
            'type' => 'roleplay',
            'items' => [
                [
                    'id' => 'q1',
                    'prompt' => 'Roleplay a conversation at a restaurant',
                ],
            ],
            'scoring' => [
                'mode' => 'rubric',
                'rubric' => [
                    'criteria' => [
                        [
                            'name' => 'Communication',
                            'weight' => 1.0,
                            'levels' => [
                                ['score' => 0, 'description' => 'Poor'],
                                ['score' => 10, 'description' => 'Excellent'],
                            ],
                        ],
                    ],
                ],
                'max_score' => 10,
            ],
        ];

        // Should not throw exception
        $validated = $contract->validateTask($task);

        $this->assertEquals('roleplay', $validated['type']);
    }

    /**
     * Test note_completion type
     */
    public function test_note_completion_has_scoring_rules(): void
    {
        $contract = new QuestionTypeContract;

        $task = [
            'type' => 'note_completion',
            'items' => [
                [
                    'id' => 'q1',
                    'prompt' => 'Complete the notes with missing information',
                ],
            ],
            'scoring' => [
                'mode' => 'exact',
                'answer_key' => ['answer1', 'answer2'],
            ],
        ];

        // Should not throw exception
        $validated = $contract->validateTask($task);

        $this->assertEquals('note_completion', $validated['type']);
    }

    /**
     * Test smart prefix detection for listen_* types
     */
    public function test_smart_prefix_detection_for_listen_types(): void
    {
        $processor = app(QuestionAudioProcessor::class);

        // Test various listen_* types
        $listenTypes = [
            'listen_mcq',
            'listen_true_false',
            'listen_yes_no_ng',
            'listen_future_type', // Hypothetical future type
        ];

        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('shouldGenerateAudio');
        $method->setAccessible(true);

        foreach ($listenTypes as $type) {
            $question = Question::factory()->create([
                'type' => $type,
                'stimulus' => ['text_html' => 'Test audio content.'],
                'audio_file_path' => null,
            ]);

            $shouldGenerate = $method->invoke($processor, $question);

            $this->assertTrue(
                $shouldGenerate,
                "Type '{$type}' should require audio due to 'listen_' prefix"
            );
        }
    }

    /**
     * Test that Question records can be created for all new types
     */
    public function test_questions_can_be_created_for_new_types(): void
    {
        $exam = Exam::factory()->create();

        $newTypes = [
            'listen_true_false',
            'listen_yes_no_ng',
            'translation',
            'roleplay',
            'note_completion',
        ];

        foreach ($newTypes as $type) {
            $question = Question::factory()->create([
                'exam_id' => $exam->id,
                'type' => $type,
                'instructions' => ['brief' => "Test {$type}"],
            ]);

            $this->assertDatabaseHas('questions', [
                'id' => $question->id,
                'type' => $type,
            ]);
        }
    }
}
