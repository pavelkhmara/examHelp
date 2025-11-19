<?php

namespace Tests\Feature\Research;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\GenerationPlan;
use App\Models\GenerationTask;
use App\Services\LanguageApp\AiProvider;
use App\Services\LanguageApp\AssemblyResolver;
use App\Services\LanguageApp\ExamResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\ExamTestHelper;
use Tests\TestCase;

/**
 * E2E тесты для Research Pipeline V2
 *
 * Проверяет полный flow v2 architecture:
 * - Phase A: Skeleton generation
 * - Phase B: Assembly plan generation
 * - AssemblyResolver: Generation plan creation
 */
class ResearchPipelineV2Test extends TestCase
{
    use RefreshDatabase;

    protected ExamResearchService $researchService;
    protected AssemblyResolver $assemblyResolver;

    protected function setUp(): void
    {
        parent::setUp();

        // Загрузить фикстуры AI ответов
        $phaseAResponse = json_decode(
            file_get_contents(__DIR__ . '/../../Fixtures/AI_Responses/ValidPhaseAResponse.json'),
            true
        );

        $phaseBResponse = json_decode(
            file_get_contents(__DIR__ . '/../../Fixtures/AI_Responses/ValidPhaseBResponse.json'),
            true
        );

        // Создать mock AI provider
        $mockAi = $this->createMock(AiProvider::class);

        // Настроить mock для возврата Phase A и Phase B responses по очереди
        $mockAi->method('generate')
            ->willReturnOnConsecutiveCalls(
                ['ok' => true, 'content' => json_encode($phaseAResponse)],
                ['ok' => true, 'content' => json_encode($phaseBResponse)],
                ['ok' => true, 'content' => json_encode($phaseAResponse)],  // Для дополнительных тестов
                ['ok' => true, 'content' => json_encode($phaseBResponse)]
            );

        // Заменить AI provider в контейнере
        $this->app->instance(AiProvider::class, $mockAi);

        $this->researchService = app(ExamResearchService::class);
        $this->assemblyResolver = app(AssemblyResolver::class);
    }

    /**
     * Тест: runPhaseA() создает skeleton structure в exam.meta['structure_v2']
     */
    public function test_phase_a_creates_skeleton_structure(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $task = GenerationTask::factory()->create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'running',
        ]);

        // Act
        $result = $this->researchService->runPhaseA($exam, $task);

        // Assert: проверить что Phase A вернула валидный skeleton
        $this->assertIsArray($result);
        $this->assertArrayHasKey('sections', $result);
        $this->assertArrayHasKey('pass_policy', $result);

        // Проверить что exam.meta содержит structure_v2
        $exam->refresh();
        $this->assertArrayHasKey('structure_v2', $exam->meta);

        // Проверить основные поля skeleton
        $structureV2 = $exam->meta['structure_v2'];
        $this->assertArrayHasKey('pass_policy', $structureV2);
        $this->assertArrayHasKey('sections', $structureV2);

        // Проверить что sections существуют
        $this->assertIsArray($structureV2['sections']);
        $this->assertGreaterThan(0, count($structureV2['sections']), 'Should have at least one section');

        // Проверить структуру первой секции
        $firstSection = $structureV2['sections'][0];
        $this->assertArrayHasKey('id', $firstSection);
        $this->assertArrayHasKey('skill', $firstSection);
        $this->assertArrayHasKey('duration_min', $firstSection);
    }

    /**
     * Тест: runPhaseB() добавляет assembly configs в sections
     */
    public function test_phase_b_creates_assembly_configs(): void
    {
        // Arrange: создать exam с Phase A skeleton
        $exam = ExamTestHelper::createExamWithPhaseA();
        $task = GenerationTask::factory()->create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'running',
        ]);

        // Получить skeleton из Phase A
        $phaseASkeleton = $exam->meta['structure_v2'];

        // Act: запустить Phase B
        $result = $this->researchService->runPhaseB($exam, $task, $phaseASkeleton);

        // Assert: проверить что Phase B вернула структуру с assembly configs
        $this->assertIsArray($result);
        $this->assertArrayHasKey('sections', $result);

        // Проверить что exam.meta['structure_v2'] обновлен
        $exam->refresh();
        $structureV2 = $exam->meta['structure_v2'];

        // Проверить что каждая секция имеет assembly config
        foreach ($structureV2['sections'] as $section) {
            $this->assertArrayHasKey('assembly', $section, "Section '{$section['id']}' should have assembly config");
            $this->assertArrayHasKey('mode', $section['assembly']);

            // Проверить что mode один из допустимых
            $this->assertContains($section['assembly']['mode'], ['pool', 'blueprint', 'inline']);
        }
    }

    /**
     * Тест: AssemblyResolver создает GenerationPlan из Phase B assembly configs
     */
    public function test_assembly_resolver_creates_generation_plans(): void
    {
        // Arrange: создать exam с Phase B (полной structure_v2)
        $exam = ExamTestHelper::createExamWithPhaseB();

        // Создать ExamCategories для каждой секции
        foreach ($exam->meta['structure_v2']['sections'] as $sectionData) {
            ExamCategory::factory()->create([
                'exam_id' => $exam->id,
                'skill' => $sectionData['skill'],
                'name' => ucfirst($sectionData['skill']),
            ]);
        }

        // Act: запустить AssemblyResolver
        $plans = $this->assemblyResolver->resolve($exam);

        // Assert
        $this->assertIsArray($plans);
        $this->assertGreaterThan(0, count($plans), 'Should create at least one generation plan');

        // Проверить что планы сохранены в БД
        $this->assertDatabaseCount('generation_plans', count($plans));

        // Проверить структуру первого плана
        $firstPlan = $plans[0];
        $this->assertInstanceOf(GenerationPlan::class, $firstPlan);
        $this->assertEquals($exam->id, $firstPlan->exam_id);
        $this->assertNotNull($firstPlan->section_id);
        $this->assertNotEmpty($firstPlan->assembly_mode);
        $this->assertIsArray($firstPlan->plan_data);
        $this->assertGreaterThan(0, $firstPlan->total_questions);
    }

    /**
     * Тест: полный pipeline Phase A → Phase B → AssemblyResolver
     */
    public function test_full_v2_pipeline_creates_complete_structure(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $task = GenerationTask::factory()->create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'running',
        ]);

        // Act: Step 1 - Phase A
        $phaseAResult = $this->researchService->runPhaseA($exam, $task);
        $this->assertIsArray($phaseAResult, 'Phase A should return an array');
        $this->assertArrayHasKey('sections', $phaseAResult, 'Phase A should return sections');

        // Act: Step 2 - Phase B
        $exam->refresh();
        $phaseASkeleton = $exam->meta['structure_v2'];
        $phaseBResult = $this->researchService->runPhaseB($exam, $task, $phaseASkeleton);
        $this->assertIsArray($phaseBResult, 'Phase B should return an array');
        $this->assertArrayHasKey('sections', $phaseBResult, 'Phase B should return sections');

        // Act: Step 3 - Create ExamCategories (normally done by OverviewStructureBuilder)
        $exam->refresh();
        foreach ($exam->meta['structure_v2']['sections'] as $sectionData) {
            ExamCategory::factory()->create([
                'exam_id' => $exam->id,
                'skill' => $sectionData['skill'],
                'name' => ucfirst($sectionData['skill']),
            ]);
        }

        // Act: Step 4 - AssemblyResolver
        $plans = $this->assemblyResolver->resolve($exam);

        // Assert: проверить конечное состояние
        $this->assertGreaterThan(0, count($plans), 'Should create generation plans');

        // Проверить что exam имеет полную v2 структуру
        $exam->refresh();
        $this->assertArrayHasKey('structure_v2', $exam->meta);
        $this->assertArrayHasKey('pass_policy', $exam->meta['structure_v2']);
        $this->assertArrayHasKey('sections', $exam->meta['structure_v2']);

        // Проверить что sections имеют assembly configs
        foreach ($exam->meta['structure_v2']['sections'] as $section) {
            $this->assertArrayHasKey('assembly', $section);
            $this->assertArrayHasKey('mode', $section['assembly']);
        }

        // Проверить что ExamCategories созданы
        $categories = ExamCategory::where('exam_id', $exam->id)->get();
        $this->assertGreaterThan(0, $categories->count());

        // Проверить что GenerationPlans созданы
        $plansInDb = GenerationPlan::where('exam_id', $exam->id)->get();
        $this->assertGreaterThan(0, $plansInDb->count());
        $this->assertEquals(count($plans), $plansInDb->count());

        // Проверить что каждый plan имеет корректные данные
        foreach ($plans as $plan) {
            $this->assertNotNull($plan->plan_data);
            $this->assertGreaterThan(0, $plan->total_questions);
            $this->assertEquals('pending', $plan->status);
        }
    }

    /**
     * Тест: Phase A сохраняет activity logs
     */
    public function test_phase_a_saves_activity_logs(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $task = GenerationTask::factory()->create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'running',
        ]);

        // Act
        $this->researchService->runPhaseA($exam, $task);

        // Assert: проверить что activity logs созданы
        $task->refresh();
        $this->assertNotEmpty($task->activities);

        // Проверить что есть activity для phase_a_started
        $phaseAActivity = collect($task->activities)->firstWhere('event', 'phase_a_started');
        $this->assertNotNull($phaseAActivity, 'Should have phase_a_started activity');
        $this->assertStringContainsString('Phase A', $phaseAActivity['message']);
    }
}
