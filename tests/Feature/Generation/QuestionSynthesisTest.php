<?php

namespace Tests\Feature\Generation;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\GenerationPlan;
use App\Models\Question;
use App\Services\LanguageApp\QuestionSynthesizer;
use App\Services\LanguageApp\QuestionValidator;
use App\Services\LanguageApp\QuestionDeduplicator;
use App\Services\LanguageApp\QuestionAttacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionSynthesisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set mock AI provider for tests
        config(['ai.provider' => 'mock']);
    }

    public function test_synthesizer_generates_valid_question()
    {
        // Arrange
        $exam = Exam::factory()->create([
            'title' => 'Test Exam',
            'level' => 'B2',
        ]);

        $section = ExamCategory::create([
            'exam_id' => $exam->id,
            'name' => 'reading',
            'meta' => [
                'skill' => 'reading',
                'duration_min' => 60,
                'max_score' => 40,
            ],
        ]);

        $plan = GenerationPlan::create([
            'exam_id' => $exam->id,
            'section_id' => $section->id,
            'status' => 'pending',
            'unit_slots' => [
                [
                    'unit' => 'u1',
                    'task_name' => 'Multiple Choice',
                    'count' => 1,
                ],
            ],
        ]);

        $synthesizer = app(QuestionSynthesizer::class);

        // Act
        $result = $synthesizer->synthesize($plan);

        // Assert
        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, count($result['questions']));

        $question = $result['questions'][0];
        $this->assertArrayHasKey('id', $question);
        $this->assertArrayHasKey('type', $question);
        $this->assertArrayHasKey('body', $question);
    }

    public function test_validator_validates_question_structure()
    {
        // Arrange
        $validator = app(QuestionValidator::class);

        $validQuestion = [
            'id' => 'q1',
            'type' => 'single_select',
            'body' => [
                'stem' => 'What is the capital of France?',
                'options' => [
                    ['text' => 'London', 'correct' => false],
                    ['text' => 'Paris', 'correct' => true],
                    ['text' => 'Berlin', 'correct' => false],
                ],
            ],
            'scoring' => [
                'max_score' => 1,
                'rules' => [],
            ],
        ];

        // Act
        $result = $validator->validate($validQuestion);

        // Assert
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_validator_detects_invalid_question()
    {
        // Arrange
        $validator = app(QuestionValidator::class);

        $invalidQuestion = [
            'id' => 'q1',
            // Missing 'type' field
            'body' => [],
        ];

        // Act
        $result = $validator->validate($invalidQuestion);

        // Assert
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_deduplicator_detects_duplicate_questions()
    {
        // Arrange
        $exam = Exam::factory()->create();

        $section = ExamCategory::create([
            'exam_id' => $exam->id,
            'name' => 'reading',
            'meta' => [],
        ]);

        // Create existing question
        Question::create([
            'exam_id' => $exam->id,
            'section_id' => $section->id,
            'question_id' => 'q1',
            'type' => 'single_select',
            'body' => [
                'stem' => 'What is 2+2?',
                'options' => [
                    ['text' => '3', 'correct' => false],
                    ['text' => '4', 'correct' => true],
                ],
            ],
            'scoring' => ['max_score' => 1],
        ]);

        $deduplicator = app(QuestionDeduplicator::class);

        $newQuestion = [
            'id' => 'q2', // Different ID
            'type' => 'single_select',
            'body' => [
                'stem' => 'What is 2+2?', // Same stem
                'options' => [
                    ['text' => '3', 'correct' => false],
                    ['text' => '4', 'correct' => true],
                ],
            ],
            'scoring' => ['max_score' => 1],
        ];

        // Act
        $isDuplicate = $deduplicator->isDuplicate($exam->id, $newQuestion);

        // Assert
        $this->assertTrue($isDuplicate);
    }

    public function test_attacher_saves_question_to_database()
    {
        // Arrange
        $exam = Exam::factory()->create();

        $section = ExamCategory::create([
            'exam_id' => $exam->id,
            'name' => 'reading',
            'meta' => [],
        ]);

        $plan = GenerationPlan::create([
            'exam_id' => $exam->id,
            'section_id' => $section->id,
            'status' => 'completed',
            'unit_slots' => [],
        ]);

        $attacher = app(QuestionAttacher::class);

        $question = [
            'id' => 'q1',
            'type' => 'single_select',
            'body' => [
                'stem' => 'Test question',
                'options' => [
                    ['text' => 'A', 'correct' => false],
                    ['text' => 'B', 'correct' => true],
                ],
            ],
            'scoring' => ['max_score' => 1],
        ];

        // Act
        $attacher->attach($plan, [$question]);

        // Assert
        $savedQuestion = Question::where('exam_id', $exam->id)
            ->where('question_id', 'q1')
            ->first();

        $this->assertNotNull($savedQuestion);
        $this->assertEquals('single_select', $savedQuestion->type);
        $this->assertEquals($question['body'], $savedQuestion->body);

        // Verify plan updated
        $plan->refresh();
        $this->assertEquals('attached', $plan->status);
        $this->assertEquals(1, $plan->generated_count);
    }

    public function test_question_synthesis_updates_generation_plan()
    {
        // Arrange
        $exam = Exam::factory()->create();

        $section = ExamCategory::create([
            'exam_id' => $exam->id,
            'name' => 'reading',
            'meta' => [],
        ]);

        $plan = GenerationPlan::create([
            'exam_id' => $exam->id,
            'section_id' => $section->id,
            'status' => 'pending',
            'unit_slots' => [
                ['unit' => 'u1', 'task_name' => 'Test', 'count' => 2],
            ],
        ]);

        $synthesizer = app(QuestionSynthesizer::class);

        // Act
        $result = $synthesizer->synthesize($plan);

        // Assert
        $this->assertTrue($result['success']);

        $plan->refresh();
        $this->assertEquals('completed', $plan->status);
        $this->assertGreaterThan(0, $plan->generated_count);
    }
}
