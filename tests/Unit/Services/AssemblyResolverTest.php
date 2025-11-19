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
        $this->resolver = new AssemblyResolver();
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
}
