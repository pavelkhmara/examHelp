<?php

namespace Tests\Unit\Services;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\GenerationPlan;
use App\Services\LanguageApp\AssemblyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\ExamTestHelper;
use Tests\TestCase;

/**
 * Тесты для AssemblyResolver - разрешение assembly configurations из Phase B
 */
class AssemblyResolverTest extends TestCase
{
    use RefreshDatabase;

    protected AssemblyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        // Use Laravel's service container to resolve dependencies
        $this->resolver = app(AssemblyResolver::class);
    }

    /**
     * Тест: resolve() создает GenerationPlan для всех секций
     */
    public function test_resolve_creates_generation_plans_for_all_sections(): void
    {
        // Arrange: создать exam с Phase B structure (2 секции)
        $exam = ExamTestHelper::createExamWithAllFields();

        // Создать ExamCategory для каждой секции
        $listening = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'skill' => 'listening',
            'name' => 'Listening',
        ]);

        $reading = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'skill' => 'reading',
            'name' => 'Reading',
        ]);

        // Добавить structure_v2 с assembly configs
        $exam->meta = [
            'structure_v2' => [
                'sections' => [
                    [
                        'id' => 'listening-section',
                        'skill' => 'listening',
                        'assembly' => [
                            'mode' => 'pool',
                            'pool_id' => 'listening-pool',
                            'pick' => 40,
                            'filters' => ['type' => ['single_select']],
                            'assertions' => ['total_tasks_equals' => 40],
                        ],
                    ],
                    [
                        'id' => 'reading-section',
                        'skill' => 'reading',
                        'assembly' => [
                            'mode' => 'blueprint',
                            'blueprint' => [
                                ['slot' => 'slot-1', 'from_pool' => 'reading-pool', 'pick' => 10, 'filters' => []],
                                ['slot' => 'slot-2', 'from_pool' => 'reading-pool', 'pick' => 10, 'filters' => []],
                            ],
                            'assertions' => ['total_tasks_equals' => 20],
                        ],
                    ],
                ],
            ],
        ];
        $exam->save();

        // Act
        $plans = $this->resolver->resolve($exam);

        // Assert
        $this->assertCount(2, $plans, 'Should create 2 generation plans');
        $this->assertDatabaseCount('generation_plans', 2);

        // Проверить, что планы созданы для правильных секций
        $listPlan = GenerationPlan::where('section_id', $listening->id)->first();
        $this->assertNotNull($listPlan);
        $this->assertEquals('pool', $listPlan->assembly_mode);
        $this->assertEquals(40, $listPlan->total_questions);

        $readPlan = GenerationPlan::where('section_id', $reading->id)->first();
        $this->assertNotNull($readPlan);
        $this->assertEquals('blueprint', $readPlan->assembly_mode);
        $this->assertEquals(20, $readPlan->total_questions);
    }

    /**
     * Тест: resolve() с pool mode создает правильный plan
     */
    public function test_resolve_pool_mode_creates_correct_plan(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'skill' => 'listening',
        ]);

        $exam->meta = [
            'structure_v2' => [
                'sections' => [
                    [
                        'id' => 'listening',
                        'skill' => 'listening',
                        'assembly' => [
                            'mode' => 'pool',
                            'pool_id' => 'test-pool',
                            'pick' => 25,
                            'filters' => ['difficulty' => 'medium'],
                            'seed' => 'test-seed',
                            'assertions' => ['total_tasks_equals' => 25],
                        ],
                    ],
                ],
            ],
        ];
        $exam->save();

        // Act
        $plans = $this->resolver->resolve($exam);

        // Assert
        $plan = $plans[0];
        $this->assertEquals('pool', $plan->assembly_mode);
        $this->assertEquals(25, $plan->total_questions);
        $this->assertEquals('pending', $plan->status);
        $this->assertEquals('test-pool', $plan->plan_data['pool_id']);
        $this->assertEquals(['difficulty' => 'medium'], $plan->plan_data['filters']);
        $this->assertEquals(25, $plan->plan_data['pick']);
        $this->assertEquals('test-seed', $plan->plan_data['seed']);
    }

    /**
     * Тест: resolve() с blueprint mode создает правильный plan
     */
    public function test_resolve_blueprint_mode_creates_correct_plan(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'skill' => 'reading',
        ]);

        $exam->meta = [
            'structure_v2' => [
                'sections' => [
                    [
                        'id' => 'reading',
                        'skill' => 'reading',
                        'assembly' => [
                            'mode' => 'blueprint',
                            'blueprint' => [
                                [
                                    'slot' => 'easy-questions',
                                    'from_pool' => 'reading-pool',
                                    'pick' => 5,
                                    'filters' => ['difficulty' => 'easy'],
                                ],
                                [
                                    'slot' => 'hard-questions',
                                    'from_pool' => 'reading-pool',
                                    'pick' => 5,
                                    'filters' => ['difficulty' => 'hard'],
                                ],
                            ],
                            'assertions' => ['total_tasks_equals' => 10],
                        ],
                    ],
                ],
            ],
        ];
        $exam->save();

        // Act
        $plans = $this->resolver->resolve($exam);

        // Assert
        $plan = $plans[0];
        $this->assertEquals('blueprint', $plan->assembly_mode);
        $this->assertEquals(10, $plan->total_questions);
        $this->assertCount(2, $plan->plan_data['slots']);
        $this->assertEquals('easy-questions', $plan->plan_data['slots'][0]['slot']);
        $this->assertEquals(5, $plan->plan_data['slots'][0]['pick']);
    }

    /**
     * Тест: resolve() с inline mode создает правильный plan
     */
    public function test_resolve_inline_mode_creates_correct_plan(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'skill' => 'writing',
        ]);

        $exam->meta = [
            'structure_v2' => [
                'sections' => [
                    [
                        'id' => 'writing',
                        'skill' => 'writing',
                        'assembly' => [
                            'mode' => 'inline',
                            'placeholders' => [
                                ['id' => 'task-1', 'type' => 'essay'],
                                ['id' => 'task-2', 'type' => 'summary'],
                            ],
                            'assertions' => ['total_tasks_equals' => 2],
                        ],
                    ],
                ],
            ],
        ];
        $exam->save();

        // Act
        $plans = $this->resolver->resolve($exam);

        // Assert
        $plan = $plans[0];
        $this->assertEquals('inline', $plan->assembly_mode);
        $this->assertEquals(2, $plan->total_questions);
        $this->assertCount(2, $plan->plan_data['placeholders']);
        $this->assertEquals('task-1', $plan->plan_data['placeholders'][0]['id']);
        $this->assertEquals('essay', $plan->plan_data['placeholders'][0]['type']);
    }

    /**
     * Тест: resolve() выбрасывает исключение когда нет structure_v2
     */
    public function test_resolve_throws_exception_when_no_structure(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        // meta не содержит structure_v2

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('structure_v2 not found in exam meta');

        // Act
        $this->resolver->resolve($exam);
    }

    /**
     * Тест: resolve() выбрасывает исключение когда нет соответствующей ExamCategory
     */
    public function test_resolve_throws_exception_when_no_matching_category(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();

        // Создать structure_v2, но НЕ создавать ExamCategory
        $exam->meta = [
            'structure_v2' => [
                'sections' => [
                    [
                        'id' => 'listening',
                        'skill' => 'listening',
                        'assembly' => [
                            'mode' => 'pool',
                            'pool_id' => 'test-pool',
                            'pick' => 10,
                            'assertions' => ['total_tasks_equals' => 10],
                        ],
                    ],
                ],
            ],
        ];
        $exam->save();

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("ExamCategory not found for skill 'listening'");

        // Act
        $this->resolver->resolve($exam);
    }

    /**
     * Тест: resolve() обновляет существующий plan вместо создания дубликата
     */
    public function test_resolve_updates_existing_plan(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'skill' => 'listening',
        ]);

        // Создать существующий plan
        $existingPlan = GenerationPlan::create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'assembly_mode' => 'pool',
            'plan_data' => ['old' => 'data'],
            'status' => 'completed',
            'total_questions' => 10,
            'generated_questions' => 10,
        ]);

        // Новая structure с другими параметрами
        $exam->meta = [
            'structure_v2' => [
                'sections' => [
                    [
                        'id' => 'listening',
                        'skill' => 'listening',
                        'assembly' => [
                            'mode' => 'pool',
                            'pool_id' => 'new-pool',
                            'pick' => 20,
                            'filters' => [],
                            'assertions' => ['total_tasks_equals' => 20],
                        ],
                    ],
                ],
            ],
        ];
        $exam->save();

        // Act
        $plans = $this->resolver->resolve($exam);

        // Assert
        $this->assertCount(1, $plans);
        $this->assertDatabaseCount('generation_plans', 1);

        $plan = $plans[0];
        $this->assertEquals($existingPlan->id, $plan->id, 'Should be the same plan');
        $this->assertEquals('new-pool', $plan->plan_data['pool_id'], 'Plan data should be updated');
        $this->assertEquals(20, $plan->total_questions, 'Total questions should be updated');
        $this->assertEquals('pending', $plan->status, 'Status should be reset to pending');
        $this->assertEquals(0, $plan->generated_questions, 'Generated count should be reset');
    }

    /**
     * Тест: resolve() с inline mode + question_groups создает правильный plan
     */
    public function test_resolve_inline_mode_with_question_groups_creates_correct_plan(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'skill' => 'listening',
        ]);

        $exam->meta = [
            'structure_v2' => [
                'sections' => [
                    [
                        'id' => 'listening',
                        'skill' => 'listening',
                        'assembly' => [
                            'mode' => 'inline',
                            'question_groups' => [
                                [
                                    'id' => 'listening-group-1',
                                    'title' => 'Zadanie I',
                                    'stimulus' => [
                                        'audio' => ['https://example.com/audio-1.mp3'],
                                    ],
                                    'playback_settings' => [
                                        'max_plays' => 1,
                                        'enforcement' => 'strict',
                                    ],
                                    'questions' => [
                                        ['id' => 'q1', 'type' => 'single_select'],
                                        ['id' => 'q2', 'type' => 'single_select'],
                                    ],
                                ],
                            ],
                            'assertions' => ['total_tasks_equals' => 2],
                        ],
                    ],
                ],
            ],
        ];
        $exam->save();

        // Act
        $plans = $this->resolver->resolve($exam);

        // Assert
        $plan = $plans[0];
        $this->assertEquals('inline', $plan->assembly_mode);
        $this->assertEquals(2, $plan->total_questions);
        $this->assertArrayHasKey('question_groups', $plan->plan_data);
        $this->assertCount(1, $plan->plan_data['question_groups']);

        $group = $plan->plan_data['question_groups'][0];
        $this->assertEquals('listening-group-1', $group['id']);
        $this->assertEquals('Zadanie I', $group['title']);
        $this->assertCount(2, $group['questions']);
        $this->assertEquals(1, $group['playback_settings']['max_plays']);
        $this->assertEquals('strict', $group['playback_settings']['enforcement']);
    }

    /**
     * Тест: resolve() с несколькими question_groups правильно считает total_questions
     */
    public function test_resolve_inline_mode_with_multiple_question_groups_calculates_total(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'skill' => 'listening',
        ]);

        $exam->meta = [
            'structure_v2' => [
                'sections' => [
                    [
                        'id' => 'listening',
                        'skill' => 'listening',
                        'assembly' => [
                            'mode' => 'inline',
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
                            'assertions' => ['total_tasks_equals' => 5],
                        ],
                    ],
                ],
            ],
        ];
        $exam->save();

        // Act
        $plans = $this->resolver->resolve($exam);

        // Assert
        $plan = $plans[0];
        $this->assertEquals(5, $plan->total_questions, 'Should sum questions from all groups (3 + 2)');
        $this->assertCount(2, $plan->plan_data['question_groups']);
    }

    /**
     * Тест: resolve() с question_groups добавляет question_group_ref к каждому вопросу
     */
    public function test_resolve_inline_mode_adds_question_group_ref_to_questions(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'skill' => 'listening',
        ]);

        $exam->meta = [
            'structure_v2' => [
                'sections' => [
                    [
                        'id' => 'listening',
                        'skill' => 'listening',
                        'assembly' => [
                            'mode' => 'inline',
                            'question_groups' => [
                                [
                                    'id' => 'test-group',
                                    'title' => 'Test Group',
                                    'stimulus' => ['text_html' => '<p>Test stimulus</p>'],
                                    'questions' => [
                                        ['id' => 'q1', 'type' => 'single_select'],
                                        ['id' => 'q2', 'type' => 'multi_select'],
                                    ],
                                ],
                            ],
                            'assertions' => ['total_tasks_equals' => 2],
                        ],
                    ],
                ],
            ],
        ];
        $exam->save();

        // Act
        $plans = $this->resolver->resolve($exam);

        // Assert
        $group = $plans[0]->plan_data['question_groups'][0];
        foreach ($group['questions'] as $question) {
            $this->assertEquals('test-group', $question['question_group_ref'], 'Each question should have question_group_ref');
        }
    }

    /**
     * Тест: resolve() с question_groups устанавливает defaults для playback_settings
     */
    public function test_resolve_inline_mode_normalizes_playback_settings(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'skill' => 'listening',
        ]);

        $exam->meta = [
            'structure_v2' => [
                'sections' => [
                    [
                        'id' => 'listening',
                        'skill' => 'listening',
                        'assembly' => [
                            'mode' => 'inline',
                            'question_groups' => [
                                [
                                    'id' => 'group-1',
                                    'title' => 'Test',
                                    'stimulus' => ['audio' => ['audio.mp3']],
                                    'playback_settings' => [
                                        'max_plays' => 2,
                                        // Остальные поля не указаны - должны быть defaults
                                    ],
                                    'questions' => [
                                        ['id' => 'q1', 'type' => 'single_select'],
                                        ['id' => 'q2', 'type' => 'single_select'],
                                    ],
                                ],
                            ],
                            'assertions' => ['total_tasks_equals' => 2],
                        ],
                    ],
                ],
            ],
        ];
        $exam->save();

        // Act
        $plans = $this->resolver->resolve($exam);

        // Assert
        $settings = $plans[0]->plan_data['question_groups'][0]['playback_settings'];
        $this->assertEquals(2, $settings['max_plays']);
        $this->assertEquals('none', $settings['enforcement'], 'Default enforcement should be "none"');
        $this->assertTrue($settings['show_counter'], 'Default show_counter should be true');
        $this->assertTrue($settings['reset_on_navigation'], 'Default reset_on_navigation should be true');
    }

    /**
     * Тест: resolve() приоритизирует question_groups над placeholders (backward compatibility)
     */
    public function test_resolve_inline_mode_prioritizes_question_groups_over_placeholders(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'skill' => 'listening',
        ]);

        // Assembly с ОБОИМИ: question_groups и placeholders
        $exam->meta = [
            'structure_v2' => [
                'sections' => [
                    [
                        'id' => 'listening',
                        'skill' => 'listening',
                        'assembly' => [
                            'mode' => 'inline',
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
                            'placeholders' => [
                                ['id' => 'legacy-task', 'type' => 'essay'],
                            ],
                            'assertions' => ['total_tasks_equals' => 2],
                        ],
                    ],
                ],
            ],
        ];
        $exam->save();

        // Act
        $plans = $this->resolver->resolve($exam);

        // Assert
        $plan = $plans[0];
        $this->assertArrayHasKey('question_groups', $plan->plan_data, 'Should use question_groups');
        $this->assertArrayNotHasKey('placeholders', $plan->plan_data, 'Should NOT use placeholders');
        $this->assertEquals(2, $plan->total_questions);
    }

    /**
     * Тест: resolve() выбрасывает исключение при невалидной question_group (< 2 вопросов)
     */
    public function test_resolve_inline_mode_throws_exception_for_invalid_question_group(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'skill' => 'listening',
        ]);

        // question_group с только 1 вопросом (минимум 2)
        $exam->meta = [
            'structure_v2' => [
                'sections' => [
                    [
                        'id' => 'listening',
                        'skill' => 'listening',
                        'assembly' => [
                            'mode' => 'inline',
                            'question_groups' => [
                                [
                                    'id' => 'invalid-group',
                                    'title' => 'Invalid',
                                    'stimulus' => ['audio' => ['audio.mp3']],
                                    'questions' => [
                                        ['id' => 'q1', 'type' => 'single_select'],
                                        // Только 1 вопрос!
                                    ],
                                ],
                            ],
                            'assertions' => ['total_tasks_equals' => 1],
                        ],
                    ],
                ],
            ],
        ];
        $exam->save();

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/at least 2 questions/');

        // Act
        $this->resolver->resolve($exam);
    }

    /**
     * Тест: resolve() выбрасывает исключение при пустом stimulus в question_group
     */
    public function test_resolve_inline_mode_throws_exception_for_empty_stimulus(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'skill' => 'listening',
        ]);

        $exam->meta = [
            'structure_v2' => [
                'sections' => [
                    [
                        'id' => 'listening',
                        'skill' => 'listening',
                        'assembly' => [
                            'mode' => 'inline',
                            'question_groups' => [
                                [
                                    'id' => 'empty-stimulus-group',
                                    'title' => 'Test',
                                    'stimulus' => [], // Пустой stimulus
                                    'questions' => [
                                        ['id' => 'q1', 'type' => 'single_select'],
                                        ['id' => 'q2', 'type' => 'single_select'],
                                    ],
                                ],
                            ],
                            'assertions' => ['total_tasks_equals' => 2],
                        ],
                    ],
                ],
            ],
        ];
        $exam->save();

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/stimulus must contain at least one/');

        // Act
        $this->resolver->resolve($exam);
    }

    /**
     * Тест: resolve() выбрасывает исключение при несовпадении assertions.total_tasks_equals
     */
    public function test_resolve_inline_mode_throws_exception_for_assertion_mismatch(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'skill' => 'listening',
        ]);

        $exam->meta = [
            'structure_v2' => [
                'sections' => [
                    [
                        'id' => 'listening',
                        'skill' => 'listening',
                        'assembly' => [
                            'mode' => 'inline',
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
                            'assertions' => ['total_tasks_equals' => 10], // Ожидается 10, но только 3
                        ],
                    ],
                ],
            ],
        ];
        $exam->save();

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/total mismatch.*3 questions.*expect 10/');

        // Act
        $this->resolver->resolve($exam);
    }

    /**
     * Тест: resolve() правильно обрабатывает question_group с несколькими типами stimulus
     */
    public function test_resolve_inline_mode_handles_multiple_stimulus_types(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $category = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'skill' => 'reading',
        ]);

        $exam->meta = [
            'structure_v2' => [
                'sections' => [
                    [
                        'id' => 'reading',
                        'skill' => 'reading',
                        'assembly' => [
                            'mode' => 'inline',
                            'question_groups' => [
                                [
                                    'id' => 'multi-stimulus-group',
                                    'title' => 'Combined Stimulus',
                                    'stimulus' => [
                                        'text_html' => '<p>Read this passage</p>',
                                        'images' => ['https://example.com/image1.jpg'],
                                        'audio' => ['https://example.com/audio.mp3'],
                                    ],
                                    'questions' => [
                                        ['id' => 'q1', 'type' => 'single_select'],
                                        ['id' => 'q2', 'type' => 'multi_select'],
                                    ],
                                ],
                            ],
                            'assertions' => ['total_tasks_equals' => 2],
                        ],
                    ],
                ],
            ],
        ];
        $exam->save();

        // Act
        $plans = $this->resolver->resolve($exam);

        // Assert
        $plan = $plans[0];
        $group = $plan->plan_data['question_groups'][0];
        $this->assertNotEmpty($group['stimulus']['text_html']);
        $this->assertNotEmpty($group['stimulus']['images']);
        $this->assertNotEmpty($group['stimulus']['audio']);
    }
}
