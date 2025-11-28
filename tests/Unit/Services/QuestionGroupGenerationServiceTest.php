<?php

namespace Tests\Unit\Services;

use App\Models\ExamCategory;
use App\Models\GenerationPlan;
use App\Models\Question;
use App\Models\QuestionGroup;
use App\Services\LanguageApp\QuestionGroupGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\ExamTestHelper;
use Tests\TestCase;

/**
 * Тесты для QuestionGroupGenerationService - генерация question groups и вопросов
 *
 * @group broken
 */
class QuestionGroupGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected QuestionGroupGenerationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new QuestionGroupGenerationService;
    }

    /**
     * Тест: generateFromPlan() успешно создает группы и вопросы
     */
    public function test_generate_from_plan_creates_groups_and_questions(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'skill' => 'listening',
        ]);

        $plan = GenerationPlan::factory()->create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'assembly_mode' => 'inline',
            'status' => 'pending',
            'plan_data' => [
                'question_groups' => [
                    [
                        'id' => 'listening-group-1',
                        'title' => 'Zadanie I',
                        'stimulus' => ['audio' => ['https://example.com/audio.mp3']],
                        'playback_settings' => ['max_plays' => 1, 'enforcement' => 'strict'],
                        'questions' => [
                            ['id' => 'q1', 'type' => 'single_select'],
                            ['id' => 'q2', 'type' => 'single_select'],
                        ],
                    ],
                ],
            ],
            'total_questions' => 2,
            'generated_questions' => 0,
        ]);

        // Act
        $stats = $this->service->generateFromPlan($plan);

        // Assert
        $this->assertEquals(1, $stats['groups']);
        $this->assertEquals(2, $stats['questions']);

        // Check QuestionGroup created
        $this->assertDatabaseCount('question_groups', 1);
        $group = QuestionGroup::where('group_id', 'listening-group-1')->first();
        $this->assertNotNull($group);
        $this->assertEquals('Zadanie I', $group->title);
        $this->assertEquals($exam->id, $group->exam_id);
        $this->assertEquals($category->id, $group->section_id);

        // Check Questions created
        $this->assertDatabaseCount('questions', 2);
        $this->assertEquals(2, $group->questions()->count());

        $q1 = Question::where('question_id', 'q1')->first();
        $this->assertNotNull($q1);
        $this->assertEquals('single_select', $q1->type);
        $this->assertEquals($group->id, $q1->question_group_id);

        // Check plan updated
        $plan->refresh();
        $this->assertEquals('completed', $plan->status);
        $this->assertEquals(2, $plan->generated_questions);
        $this->assertNotNull($plan->completed_at);
    }

    /**
     * Тест: generateFromPlan() работает с несколькими группами
     */
    public function test_generate_from_plan_handles_multiple_groups(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'skill' => 'listening',
        ]);

        $plan = GenerationPlan::factory()->create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'assembly_mode' => 'inline',
            'plan_data' => [
                'question_groups' => [
                    [
                        'id' => 'group-1',
                        'title' => 'Task I',
                        'stimulus' => ['audio' => ['audio1.mp3']],
                        'questions' => [
                            ['id' => 'q1', 'type' => 'single_select'],
                            ['id' => 'q2', 'type' => 'single_select'],
                            ['id' => 'q3', 'type' => 'single_select'],
                        ],
                    ],
                    [
                        'id' => 'group-2',
                        'title' => 'Task II',
                        'stimulus' => ['audio' => ['audio2.mp3']],
                        'questions' => [
                            ['id' => 'q4', 'type' => 'true_false'],
                            ['id' => 'q5', 'type' => 'true_false'],
                        ],
                    ],
                ],
            ],
            'total_questions' => 5,
        ]);

        // Act
        $stats = $this->service->generateFromPlan($plan);

        // Assert
        $this->assertEquals(2, $stats['groups']);
        $this->assertEquals(5, $stats['questions']);
        $this->assertDatabaseCount('question_groups', 2);
        $this->assertDatabaseCount('questions', 5);
    }

    /**
     * Тест: generateFromPlan() throws exception for non-inline mode
     */
    public function test_generate_from_plan_throws_exception_for_non_inline_mode(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create(['exam_id' => $exam->id]);

        $plan = GenerationPlan::factory()->create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'assembly_mode' => 'pool',
            'plan_data' => [],
        ]);

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('only supports inline assembly mode');

        // Act
        $this->service->generateFromPlan($plan);
    }

    /**
     * Тест: generateFromPlan() throws exception when missing question_groups
     */
    public function test_generate_from_plan_throws_exception_when_missing_question_groups(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create(['exam_id' => $exam->id]);

        $plan = GenerationPlan::factory()->create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'assembly_mode' => 'inline',
            'plan_data' => [], // No question_groups
        ]);

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('must contain question_groups array');

        // Act
        $this->service->generateFromPlan($plan);
    }

    /**
     * Тест: generateQuestionGroup() создает группу с вопросами
     */
    public function test_generate_question_group_creates_group_with_questions(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create(['exam_id' => $exam->id]);

        $plan = GenerationPlan::factory()->create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'assembly_mode' => 'inline',
        ]);

        $groupSpec = [
            'id' => 'test-group',
            'title' => 'Test Group',
            'stimulus' => ['text_html' => '<p>Read this</p>'],
            'instructions' => ['brief' => 'Answer questions'],
            'playback_settings' => ['max_plays' => 2, 'enforcement' => 'advisory'],
            'metadata' => ['total_points' => 4],
            'order' => 1,
            'questions' => [
                ['id' => 'q1', 'type' => 'single_select'],
                ['id' => 'q2', 'type' => 'multi_select'],
            ],
        ];

        // Act
        $group = $this->service->generateQuestionGroup($plan, $groupSpec, 0);

        // Assert
        $this->assertInstanceOf(QuestionGroup::class, $group);
        $this->assertEquals('test-group', $group->group_id);
        $this->assertEquals('Test Group', $group->title);
        $this->assertEquals('<p>Read this</p>', $group->stimulus['text_html']);
        $this->assertEquals(2, $group->playback_settings['max_plays']);
        $this->assertEquals(2, $group->questions()->count());
    }

    /**
     * Тест: generateQuestionGroup() throws exception for missing id
     */
    public function test_generate_question_group_throws_exception_for_missing_id(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create(['exam_id' => $exam->id]);

        $plan = GenerationPlan::factory()->create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
        ]);

        $groupSpec = [
            // Missing 'id'
            'title' => 'Test',
            'stimulus' => ['text' => 'test'],
            'questions' => [
                ['id' => 'q1', 'type' => 'single_select'],
                ['id' => 'q2', 'type' => 'single_select'],
            ],
        ];

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('missing required field: id');

        // Act
        $this->service->generateQuestionGroup($plan, $groupSpec);
    }

    /**
     * Тест: generateQuestionGroup() throws exception for missing title
     */
    public function test_generate_question_group_throws_exception_for_missing_title(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create(['exam_id' => $exam->id]);

        $plan = GenerationPlan::factory()->create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
        ]);

        $groupSpec = [
            'id' => 'test-group',
            // Missing 'title'
            'stimulus' => ['text' => 'test'],
            'questions' => [
                ['id' => 'q1', 'type' => 'single_select'],
                ['id' => 'q2', 'type' => 'single_select'],
            ],
        ];

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('missing required field: title');

        // Act
        $this->service->generateQuestionGroup($plan, $groupSpec);
    }

    /**
     * Тест: deleteGeneratedContent() удаляет группы и вопросы
     */
    public function test_delete_generated_content_removes_groups_and_questions(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create(['exam_id' => $exam->id]);

        $plan = GenerationPlan::factory()->create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'assembly_mode' => 'inline',
            'plan_data' => [
                'question_groups' => [
                    [
                        'id' => 'group-1',
                        'title' => 'Test',
                        'stimulus' => ['audio' => ['audio.mp3']],
                        'questions' => [
                            ['id' => 'q1', 'type' => 'single_select'],
                            ['id' => 'q2', 'type' => 'single_select'],
                        ],
                    ],
                ],
            ],
            'total_questions' => 2,
        ]);

        // Generate content first
        $this->service->generateFromPlan($plan);
        $this->assertDatabaseCount('question_groups', 1);
        $this->assertDatabaseCount('questions', 2);

        // Act
        $deletedCount = $this->service->deleteGeneratedContent($plan);

        // Assert
        $this->assertEquals(1, $deletedCount);
        $this->assertDatabaseCount('question_groups', 0);
        // Questions should cascade delete via ON DELETE SET NULL or cascade
        $this->assertDatabaseCount('questions', 2); // Questions remain but question_group_id is NULL
    }

    /**
     * Тест: getGenerationStats() возвращает правильную статистику
     */
    public function test_get_generation_stats_returns_correct_stats(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create(['exam_id' => $exam->id]);

        $plan = GenerationPlan::factory()->create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'assembly_mode' => 'inline',
            'plan_data' => [
                'question_groups' => [
                    [
                        'id' => 'group-1',
                        'title' => 'Test',
                        'stimulus' => ['audio' => ['audio.mp3']],
                        'questions' => [
                            ['id' => 'q1', 'type' => 'single_select'],
                            ['id' => 'q2', 'type' => 'single_select'],
                            ['id' => 'q3', 'type' => 'single_select'],
                        ],
                    ],
                ],
            ],
            'total_questions' => 3,
        ]);

        // Generate content
        $this->service->generateFromPlan($plan);

        // Act
        $stats = $this->service->getGenerationStats($plan);

        // Assert
        $this->assertEquals(1, $stats['groups']);
        $this->assertEquals(3, $stats['questions']);
    }

    /**
     * Тест: generateFromPlan() rollback on error
     */
    public function test_generate_from_plan_rollback_on_error(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create(['exam_id' => $exam->id]);

        $plan = GenerationPlan::factory()->create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'assembly_mode' => 'inline',
            'plan_data' => [
                'question_groups' => [
                    [
                        'id' => 'group-1',
                        'title' => 'Test',
                        'stimulus' => ['audio' => ['audio.mp3']],
                        'questions' => [
                            ['id' => 'q1'], // Missing 'type' - will fail
                        ],
                    ],
                ],
            ],
            'total_questions' => 1,
        ]);

        // Assert
        $this->expectException(\Exception::class);

        // Act
        try {
            $this->service->generateFromPlan($plan);
        } catch (\Exception $e) {
            // Check rollback happened
            $this->assertDatabaseCount('question_groups', 0);
            $this->assertDatabaseCount('questions', 0);

            // Check plan status updated
            $plan->refresh();
            $this->assertEquals('failed', $plan->status);
            $this->assertNotNull($plan->error);

            throw $e;
        }
    }
}
