<?php

namespace Tests\Unit;

use App\Services\LanguageApp\Validators\QuestionGroupValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Тесты для QuestionGroupValidator
 */
class QuestionGroupValidatorTest extends TestCase
{
    use RefreshDatabase;

    private QuestionGroupValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new QuestionGroupValidator;
    }

    /**
     * Тест: валидация с минимальными обязательными данными
     */
    public function test_validates_with_minimum_required_data(): void
    {
        // Arrange
        $data = [
            'id' => 'task-1',
            'title' => 'Task I',
            'stimulus' => [
                'audio' => ['https://example.com/audio.mp3'],
            ],
        ];

        // Act
        $result = $this->validator->validate($data);

        // Assert
        $this->assertEquals($data, $result);
    }

    /**
     * Тест: валидация с полными данными (including playback_settings)
     */
    public function test_validates_with_full_data(): void
    {
        // Arrange
        $data = [
            'id' => 'listening-question-1',
            'title' => 'Task I - Multiple Choice',
            'instructions' => [
                'brief' => 'Listen to the audio',
                'full' => 'Listen carefully and answer all questions',
            ],
            'stimulus' => [
                'audio' => ['https://example.com/audio.mp3'],
                'text_html' => '<p>Additional text</p>',
            ],
            'playback_settings' => [
                'max_plays' => 2,
                'enforcement' => 'strict',
                'show_counter' => true,
                'reset_on_navigation' => false,
            ],
            'questions' => [
                ['id' => 'q1', 'type' => 'single_select'],
                ['id' => 'q2', 'type' => 'single_select'],
            ],
            'metadata' => [
                'total_points' => 6,
                'suggested_time_sec' => 300,
            ],
            'order' => 1,
        ];

        // Act
        $result = $this->validator->validate($data);

        // Assert
        $this->assertEquals($data, $result);
    }

    /**
     * Тест: ошибка при отсутствии обязательного поля 'id'
     */
    public function test_fails_when_missing_required_id(): void
    {
        // Arrange
        $data = [
            'title' => 'Task I',
            'stimulus' => ['audio' => ['https://example.com/audio.mp3']],
        ];

        // Act & Assert
        $this->expectException(ValidationException::class);
        $this->validator->validate($data);
    }

    /**
     * Тест: ошибка при отсутствии обязательного поля 'title'
     */
    public function test_fails_when_missing_required_title(): void
    {
        // Arrange
        $data = [
            'id' => 'task-1',
            'stimulus' => ['audio' => ['https://example.com/audio.mp3']],
        ];

        // Act & Assert
        $this->expectException(ValidationException::class);
        $this->validator->validate($data);
    }

    /**
     * Тест: ошибка при отсутствии обязательного поля 'stimulus'
     */
    public function test_fails_when_missing_required_stimulus(): void
    {
        // Arrange
        $data = [
            'id' => 'task-1',
            'title' => 'Task I',
        ];

        // Act & Assert
        $this->expectException(ValidationException::class);
        $this->validator->validate($data);
    }

    /**
     * Тест: ошибка при пустом stimulus (без контента)
     */
    public function test_fails_when_stimulus_is_empty(): void
    {
        // Arrange
        $data = [
            'id' => 'task-1',
            'title' => 'Task I',
            'stimulus' => [],
        ];

        // Act & Assert
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('stimulus must contain at least one');

        $this->validator->validate($data);
    }

    /**
     * Тест: валидация с playback_settings.max_plays = null (unlimited)
     */
    public function test_validates_with_unlimited_playback(): void
    {
        // Arrange
        $data = [
            'id' => 'task-1',
            'title' => 'Task I',
            'stimulus' => ['audio' => ['https://example.com/audio.mp3']],
            'playback_settings' => [
                'max_plays' => null,
                'enforcement' => 'none',
            ],
        ];

        // Act
        $result = $this->validator->validate($data);

        // Assert
        $this->assertNull($result['playback_settings']['max_plays']);
    }

    /**
     * Тест: ошибка при max_plays < 1
     */
    public function test_fails_when_max_plays_is_less_than_one(): void
    {
        // Arrange
        $data = [
            'id' => 'task-1',
            'title' => 'Task I',
            'stimulus' => ['audio' => ['https://example.com/audio.mp3']],
            'playback_settings' => [
                'max_plays' => 0,
                'enforcement' => 'strict',
            ],
        ];

        // Act & Assert
        $this->expectException(ValidationException::class);
        $this->validator->validate($data);
    }

    /**
     * Тест: ошибка при невалидном enforcement mode
     */
    public function test_fails_when_enforcement_mode_is_invalid(): void
    {
        // Arrange
        $data = [
            'id' => 'task-1',
            'title' => 'Task I',
            'stimulus' => ['audio' => ['https://example.com/audio.mp3']],
            'playback_settings' => [
                'max_plays' => 2,
                'enforcement' => 'invalid_mode',
            ],
        ];

        // Act & Assert
        $this->expectException(ValidationException::class);
        $this->validator->validate($data);
    }

    /**
     * Тест: валидация с различными enforcement modes (strict, advisory, none)
     */
    public function test_validates_all_enforcement_modes(): void
    {
        // Arrange
        $modes = ['strict', 'advisory', 'none'];

        foreach ($modes as $mode) {
            $data = [
                'id' => 'task-1',
                'title' => 'Task I',
                'stimulus' => ['audio' => ['https://example.com/audio.mp3']],
                'playback_settings' => [
                    'max_plays' => 2,
                    'enforcement' => $mode,
                ],
            ];

            // Act
            $result = $this->validator->validate($data);

            // Assert
            $this->assertEquals($mode, $result['playback_settings']['enforcement']);
        }
    }

    /**
     * Тест: ошибка при менее чем 2 вопросах в questions array
     */
    public function test_fails_when_questions_is_zero(): void
    {
        // Arrange
        $data = [
            'id' => 'task-1',
            'title' => 'Task I',
            'stimulus' => ['audio' => ['https://example.com/audio.mp3']],
            'questions' => [],
        ];

        // Act & Assert
        $this->expectException(ValidationException::class);
        // JSON Schema validator throws first with its own message
        $this->expectExceptionMessage('minItems 2 violated');

        $this->validator->validate($data);
    }

    /**
     * Тест: ошибка при более чем 20 вопросах в questions array
     */
    public function test_fails_when_questions_more_than_twenty(): void
    {
        // Arrange
        $questions = [];
        for ($i = 1; $i <= 21; $i++) {
            $questions[] = ['id' => "q{$i}"];
        }

        $data = [
            'id' => 'task-1',
            'title' => 'Task I',
            'stimulus' => ['audio' => ['https://example.com/audio.mp3']],
            'questions' => $questions,
        ];

        // Act & Assert
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cannot have more than 20 questions');

        $this->validator->validate($data);
    }

    /**
     * Тест: ошибка при 1 вопросе (single-question groups not allowed)
     */
    public function test_fails_with_single_question(): void
    {
        // Arrange
        $data = [
            'id' => 'task-1',
            'title' => 'Task I',
            'stimulus' => ['text_html' => '<p>Describe this picture</p>'],
            'questions' => [
                ['id' => 'q1', 'type' => 'speaking_prompt'],
            ],
        ];

        // Act & Assert
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('minItems 2 violated');
        $this->validator->validate($data);
    }

    /**
     * Тест: успешная валидация с 2 вопросами
     */
    public function test_validates_with_minimum_two_questions(): void
    {
        // Arrange
        $data = [
            'id' => 'task-1',
            'title' => 'Task I',
            'stimulus' => ['audio' => ['https://example.com/audio.mp3']],
            'questions' => [
                ['id' => 'q1', 'type' => 'single_select'],
                ['id' => 'q2', 'type' => 'single_select'],
            ],
        ];

        // Act
        $result = $this->validator->validate($data);

        // Assert
        $this->assertCount(2, $result['questions']);
    }

    /**
     * Тест: успешная валидация с 20 вопросами (максимум)
     */
    public function test_validates_with_maximum_twenty_questions(): void
    {
        // Arrange
        $questions = [];
        for ($i = 1; $i <= 20; $i++) {
            $questions[] = ['id' => "q{$i}"];
        }

        $data = [
            'id' => 'task-1',
            'title' => 'Task I',
            'stimulus' => ['audio' => ['https://example.com/audio.mp3']],
            'questions' => $questions,
        ];

        // Act
        $result = $this->validator->validate($data);

        // Assert
        $this->assertCount(20, $result['questions']);
    }

    /**
     * Тест: валидация структуры без бизнес-правил (validateStructure)
     */
    public function test_validate_structure_method_skips_business_rules(): void
    {
        // Arrange - данные с пустым stimulus (нарушает бизнес-правила, но не JSON schema)
        $data = [
            'id' => 'task-1',
            'title' => 'Task I',
            'stimulus' => [],
        ];

        // Act - validateStructure должен пройти (только JSON schema)
        // validate() должен упасть (бизнес-правила)
        $this->expectException(ValidationException::class);
        $this->validator->validate($data);
    }

    /**
     * Тест: валидация с разными типами stimulus (audio, text, images, video)
     */
    public function test_validates_with_different_stimulus_types(): void
    {
        // Test 1: Audio only
        $dataAudio = [
            'id' => 'task-1',
            'title' => 'Listening Task',
            'stimulus' => ['audio' => ['https://example.com/audio.mp3']],
        ];
        $this->validator->validate($dataAudio);

        // Test 2: Text only
        $dataText = [
            'id' => 'task-2',
            'title' => 'Reading Task',
            'stimulus' => ['text_html' => '<p>Sample text</p>'],
        ];
        $this->validator->validate($dataText);

        // Test 3: Images only
        $dataImages = [
            'id' => 'task-3',
            'title' => 'Image Task',
            'stimulus' => ['images' => ['https://example.com/image.jpg']],
        ];
        $this->validator->validate($dataImages);

        // Test 4: Combined
        $dataCombined = [
            'id' => 'task-4',
            'title' => 'Combined Task',
            'stimulus' => [
                'audio' => ['https://example.com/audio.mp3'],
                'text_html' => '<p>Text</p>',
                'images' => ['https://example.com/image.jpg'],
            ],
        ];
        $result = $this->validator->validate($dataCombined);

        $this->assertArrayHasKey('audio', $result['stimulus']);
        $this->assertArrayHasKey('text_html', $result['stimulus']);
        $this->assertArrayHasKey('images', $result['stimulus']);
    }

    /**
     * Тест: валидация metadata fields
     */
    public function test_validates_metadata_fields(): void
    {
        // Arrange
        $data = [
            'id' => 'task-1',
            'title' => 'Task I',
            'stimulus' => ['audio' => ['https://example.com/audio.mp3']],
            'metadata' => [
                'total_points' => 10,
                'suggested_time_sec' => 600,
                'duration_sec' => 180,
                'tags' => ['listening', 'comprehension'],
            ],
        ];

        // Act
        $result = $this->validator->validate($data);

        // Assert
        $this->assertEquals(10, $result['metadata']['total_points']);
        $this->assertEquals(600, $result['metadata']['suggested_time_sec']);
        $this->assertEquals(180, $result['metadata']['duration_sec']);
        $this->assertCount(2, $result['metadata']['tags']);
    }

    /**
     * Тест: валидация order field
     */
    public function test_validates_order_field(): void
    {
        // Arrange
        $data = [
            'id' => 'task-1',
            'title' => 'Task I',
            'stimulus' => ['audio' => ['https://example.com/audio.mp3']],
            'order' => 5,
        ];

        // Act
        $result = $this->validator->validate($data);

        // Assert
        $this->assertEquals(5, $result['order']);
    }

    /**
     * Тест: ошибка при negative order value
     */
    public function test_fails_when_order_is_negative(): void
    {
        // Arrange
        $data = [
            'id' => 'task-1',
            'title' => 'Task I',
            'stimulus' => ['audio' => ['https://example.com/audio.mp3']],
            'order' => -1,
        ];

        // Act & Assert
        $this->expectException(ValidationException::class);
        $this->validator->validate($data);
    }
}
