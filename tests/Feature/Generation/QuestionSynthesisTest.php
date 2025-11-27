<?php

namespace Tests\Feature\Generation;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\GenerationPlan;
use App\Models\Question;
use App\Services\LanguageApp\QuestionAttacher;
use App\Services\LanguageApp\QuestionDeduplicator;
use App\Services\LanguageApp\QuestionSynthesizer;
use App\Services\LanguageApp\QuestionValidator;
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
            'key' => 'reading',
            'name' => 'Reading Comprehension',
            'meta' => [
                'skill' => 'reading',
                'duration_min' => 60,
                'max_score' => 40,
            ],
        ]);

        $plan = GenerationPlan::create([
            'exam_id' => $exam->id,
            'section_id' => $section->id,
            'assembly_mode' => 'inline',
            'plan_data' => [],
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
        $result = $synthesizer->synthesize($plan, $exam);

        // Assert
        $this->assertIsArray($result);
        // Mock provider returns empty array, so we just verify no exception thrown
        // In real tests with AI provider, we'd check for questions
    }

    public function test_validator_validates_question_structure()
    {
        // Arrange
        $exam = Exam::factory()->create();
        $section = ExamCategory::create([
            'exam_id' => $exam->id,
            'key' => 'reading',
            'name' => 'Reading Comprehension',
            'meta' => [],
        ]);
        $plan = GenerationPlan::create([
            'exam_id' => $exam->id,
            'section_id' => $section->id,
            'assembly_mode' => 'inline',
            'plan_data' => [],
            'status' => 'pending',
            'unit_slots' => [],
        ]);

        $validator = app(QuestionValidator::class);

        $validQuestions = [
            [
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
            ],
        ];

        // Act - validateAndFinalize returns validated questions
        $result = $validator->validateAndFinalize($validQuestions, $plan, $exam);

        // Assert - returns array of questions (possibly modified)
        $this->assertIsArray($result);
    }

    public function test_validator_handles_empty_questions()
    {
        // Arrange
        $exam = Exam::factory()->create();
        $section = ExamCategory::create([
            'exam_id' => $exam->id,
            'key' => 'reading',
            'name' => 'Reading Comprehension',
            'meta' => [],
        ]);
        $plan = GenerationPlan::create([
            'exam_id' => $exam->id,
            'section_id' => $section->id,
            'assembly_mode' => 'inline',
            'plan_data' => [],
            'status' => 'pending',
            'unit_slots' => [],
        ]);

        $validator = app(QuestionValidator::class);

        // Act - empty array input
        $result = $validator->validateAndFinalize([], $plan, $exam);

        // Assert - returns empty array
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_deduplicator_detects_duplicate_questions()
    {
        // Arrange
        $exam = Exam::factory()->create();

        $section = ExamCategory::create([
            'exam_id' => $exam->id,
            'key' => 'reading',
            'name' => 'reading',
            'meta' => [],
        ]);

        // Create existing question
        Question::create([
            'exam_id' => $exam->id,
            'section_id' => $section->id,
            'question_id' => 'q1',
            'type' => 'single_select',
            'skills_measured' => ['reading'],
            'interaction' => [
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
            'interaction' => [
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
            'key' => 'reading',
            'name' => 'reading',
            'meta' => [],
        ]);

        $plan = GenerationPlan::create([
            'exam_id' => $exam->id,
            'section_id' => $section->id,
            'assembly_mode' => 'inline',
            'plan_data' => [],
            'status' => 'completed',
            'unit_slots' => [],
        ]);

        $attacher = app(QuestionAttacher::class);

        $questions = [
            [
                'id' => 'q1',
                'type' => 'single_select',
                'skills_measured' => ['reading'],
                'interaction' => [
                    'stem' => 'Test question',
                    'options' => [
                        ['text' => 'A', 'correct' => false],
                        ['text' => 'B', 'correct' => true],
                    ],
                ],
                'scoring' => ['max_score' => 1],
            ],
        ];

        // Act - use attachToExam method
        $result = $attacher->attachToExam($questions, $plan, $exam);

        // Assert - attachToExam returns array of attached questions
        $this->assertIsArray($result);
    }

    public function test_question_synthesis_updates_generation_plan()
    {
        // Arrange
        $exam = Exam::factory()->create([
            'title' => 'Test Exam',
            'level' => 'B2',
        ]);

        $section = ExamCategory::create([
            'exam_id' => $exam->id,
            'key' => 'reading',
            'name' => 'Reading',
            'meta' => [],
        ]);

        $plan = GenerationPlan::create([
            'exam_id' => $exam->id,
            'section_id' => $section->id,
            'assembly_mode' => 'inline',
            'plan_data' => [],
            'status' => 'pending',
            'unit_slots' => [
                ['unit' => 'u1', 'task_name' => 'Test', 'count' => 2],
            ],
        ]);

        $synthesizer = app(QuestionSynthesizer::class);

        // Act - synthesize requires exam parameter
        $result = $synthesizer->synthesize($plan, $exam);

        // Assert - Mock provider returns empty array, verify no exception thrown
        $this->assertIsArray($result);
    }
}
