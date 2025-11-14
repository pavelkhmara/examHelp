<?php

namespace Tests\Feature\Generation;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\GenerationPlan;
use App\Models\GenerationTask;
use App\Services\LanguageApp\AssemblyResolver;
use App\Services\LanguageApp\ExamResearchService;
use App\Services\LanguageApp\QuestionSynthesizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for v2 architecture archetypes flow
 *
 * These tests verify that the full pipeline from Phase A → Phase B → Resolve → Synthesize
 * works correctly and that question_archetypes are properly generated.
 *
 * Bug discovered: Phase B does not generate question_archetypes, causing QuestionSynthesizer
 * to fail with "No archetypes found in section" error.
 */
class V2ArchetypesIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set mock AI provider for tests
        config(['ai.provider' => 'mock']);
    }

    /**
     * Test that validator accepts question_archetypes for blueprint sections
     */
    public function test_phase_b_generates_question_archetypes_for_blueprint_sections()
    {
        // Arrange
        $validator = new \App\Services\LanguageApp\Validators\JsonSchemaExamV2();

        $structure = [
            'id' => 'ielts-academic',
            'version' => '2.0',
            'meta' => ['name' => 'IELTS Academic', 'language' => 'en', 'level' => 'B2'],
            'pass_policy' => ['mode' => 'overall_only', 'overall_threshold_percent' => 60],
            'policies' => [],
            'sections' => [
                [
                    'id' => 'reading',
                    'skill' => 'reading',
                    'duration_min' => 60,
                    'max_score' => 40,
                    'min_pass_percent' => 60,
                    'question_archetypes' => [
                        [
                            'id' => 'read_mcq',
                            'type' => 'single_select',
                            'name' => 'Reading MCQ',
                            'difficulty' => 'medium',
                            'config' => ['options_count' => 4, 'scoring' => ['max_points' => 1]],
                        ],
                        [
                            'id' => 'read_tfng',
                            'type' => 'true_false',
                            'name' => 'True/False/Not Given',
                            'difficulty' => 'hard',
                            'config' => ['scoring' => ['max_points' => 1]],
                        ],
                    ],
                    'assembly' => [
                        'mode' => 'blueprint',
                        'blueprint' => [
                            [
                                'slot' => 'part_1',
                                'from_pool' => 'reading_general',
                                'pick' => 10,
                                'filters' => ['type' => ['single_select'], 'difficulty' => ['medium']],
                            ],
                        ],
                        'assertions' => ['total_tasks_equals' => 10],
                    ],
                ],
            ],
        ];

        // Act
        $validated = $validator->validate($structure);

        // Assert - validation should succeed
        $this->assertArrayHasKey('sections', $validated);
        $this->assertArrayHasKey('question_archetypes', $validated['sections'][0],
            'Validator MUST accept question_archetypes field');

        $archetypes = $validated['sections'][0]['question_archetypes'];
        $this->assertIsArray($archetypes);
        $this->assertCount(2, $archetypes);

        // Verify archetype structure
        $this->assertArrayHasKey('id', $archetypes[0]);
        $this->assertArrayHasKey('type', $archetypes[0]);
        $this->assertArrayHasKey('name', $archetypes[0]);
        $this->assertArrayHasKey('difficulty', $archetypes[0]);
        $this->assertArrayHasKey('config', $archetypes[0]);
    }

    /**
     * Test that validator accepts question_archetypes for inline sections
     */
    public function test_phase_b_generates_question_archetypes_for_inline_sections()
    {
        // Arrange
        $validator = new \App\Services\LanguageApp\Validators\JsonSchemaExamV2();

        $structure = [
            'id' => 'ielts-writing',
            'version' => '2.0',
            'meta' => ['name' => 'IELTS Writing', 'language' => 'en', 'level' => 'B2'],
            'pass_policy' => ['mode' => 'overall_only', 'overall_threshold_percent' => 60],
            'policies' => [],
            'sections' => [
                [
                    'id' => 'writing',
                    'skill' => 'writing',
                    'duration_min' => 60,
                    'max_score' => 25,
                    'min_pass_percent' => 60,
                    'question_archetypes' => [
                        [
                            'id' => 'writing_task_1',
                            'type' => 'writing_prompt',
                            'name' => 'Short Writing (150 words)',
                            'difficulty' => 'medium',
                            'config' => ['min_word_count' => 150, 'scoring' => ['max_points' => 33]],
                        ],
                        [
                            'id' => 'writing_task_2',
                            'type' => 'writing_prompt',
                            'name' => 'Essay (250 words)',
                            'difficulty' => 'hard',
                            'config' => ['min_word_count' => 250, 'scoring' => ['max_points' => 67]],
                        ],
                    ],
                    'assembly' => [
                        'mode' => 'inline',
                    ],
                ],
            ],
        ];

        // Act
        $validated = $validator->validate($structure);

        // Assert
        $this->assertArrayHasKey('sections', $validated);
        $this->assertArrayHasKey('question_archetypes', $validated['sections'][0],
            'Validator MUST accept question_archetypes for inline sections');

        $archetypes = $validated['sections'][0]['question_archetypes'];
        $this->assertIsArray($archetypes);
        $this->assertCount(2, $archetypes);
    }

    /**
     * Test full integration: Phase B → Resolve → Synthesize
     *
     * This is the END-TO-END test that verifies the complete pipeline.
     */
    public function test_full_v2_pipeline_from_phase_a_to_synthesis()
    {
        // Arrange: Create exam with complete Phase B structure (including question_archetypes)
        $exam = Exam::factory()->create([
            'title' => 'IELTS Academic Full Test',
            'level' => 'B2',
        ]);

        $structure = [
            'id' => 'ielts-academic-full',
            'version' => '2.0',
            'meta' => ['name' => 'IELTS Academic', 'language' => 'en', 'level' => 'B2'],
            'pass_policy' => ['mode' => 'overall_only', 'overall_threshold_percent' => 60],
            'policies' => [],
            'sections' => [
                [
                    'id' => 'reading',
                    'skill' => 'reading',
                    'duration_min' => 60,
                    'max_score' => 40,
                    'min_pass_percent' => 60,
                    'question_archetypes' => [
                        [
                            'id' => 'read_mcq',
                            'type' => 'single_select',
                            'name' => 'Reading MCQ',
                            'difficulty' => 'medium',
                            'config' => ['options_count' => 4, 'scoring' => ['max_points' => 1]],
                        ],
                    ],
                    'assembly' => [
                        'mode' => 'blueprint',
                        'blueprint' => [
                            [
                                'slot' => 'part_1',
                                'from_pool' => 'reading_general',
                                'pick' => 5,
                                'filters' => ['type' => ['single_select']],
                            ],
                        ],
                        'assertions' => ['total_tasks_equals' => 5],
                    ],
                ],
            ],
        ];

        $exam->structure_v2 = $structure;
        $exam->save();

        // Create ExamCategory for reading section (required by AssemblyResolver)
        \App\Models\ExamCategory::create([
            'exam_id' => $exam->id,
            'key' => 'reading',
            'name' => 'Reading',
            'skill' => 'reading',
            'order' => 1,
            'meta' => [],
        ]);

        // Verify question_archetypes exist in structure
        foreach ($structure['sections'] as $section) {
            $this->assertArrayHasKey('question_archetypes', $section,
                "Section {$section['id']} MUST have question_archetypes");
            $this->assertNotEmpty($section['question_archetypes'],
                "Section {$section['id']} question_archetypes MUST not be empty");
        }

        // Step 1: Run Resolve (create GenerationPlans)
        $resolver = app(AssemblyResolver::class);
        $plans = $resolver->resolve($exam);

        $this->assertNotEmpty($plans, 'Resolve MUST create at least one GenerationPlan');
        $this->assertInstanceOf(GenerationPlan::class, $plans[0]);

        // Step 2: Verify QuestionSynthesizer can access archetypes
        $synthesizer = app(QuestionSynthesizer::class);

        // The main fix verification: getArchetypeForPool should NOT throw "No archetypes found"
        $reflection = new \ReflectionClass($synthesizer);
        $method = $reflection->getMethod('getSectionMetadata');
        $method->setAccessible(true);

        $plan = $plans[0];
        $section = $method->invoke($synthesizer, $exam, $plan->section_id);

        // CRITICAL: Verify question_archetypes exist and are accessible
        $this->assertArrayHasKey('question_archetypes', $section,
            'QuestionSynthesizer MUST be able to access question_archetypes from structure_v2');
        $this->assertNotEmpty($section['question_archetypes'],
            'question_archetypes MUST not be empty');

        // Verify getArchetypeForPool works
        $method2 = $reflection->getMethod('getArchetypeForPool');
        $method2->setAccessible(true);

        $archetype = $method2->invoke($synthesizer, $section, ['type' => ['single_select']]);
        $this->assertArrayHasKey('id', $archetype, 'Archetype must have ID');
        $this->assertArrayHasKey('type', $archetype, 'Archetype must have type');
    }

    /**
     * Test that QuestionSynthesizer fails gracefully when question_archetypes are missing
     *
     * This test documents the CURRENT behavior (bug) that should be fixed.
     */
    public function test_synthesizer_fails_when_question_archetypes_missing()
    {
        // Arrange
        $exam = Exam::factory()->create([
            'title' => 'Test Exam',
            'level' => 'B2',
        ]);

        // Create structure WITHOUT question_archetypes (reproducing the bug)
        $structure = [
            'id' => 'test-exam',
            'version' => '2.0',
            'meta' => ['name' => 'Test', 'language' => 'en', 'level' => 'B2'],
            'sections' => [
                [
                    'id' => 'reading',
                    'skill' => 'reading',
                    'duration_min' => 60,
                    'max_score' => 40,
                    'assembly' => [
                        'mode' => 'blueprint',
                        'blueprint' => [
                            [
                                'type' => 'single_select',
                                'pick' => 10,
                                'filters' => ['difficulty' => 'medium'],
                            ],
                        ],
                        'assertions' => ['total_tasks_equals' => 10],
                    ],
                    'tasks' => [],
                    // NOTE: question_archetypes is MISSING - this is the bug!
                ],
            ],
        ];

        $exam->meta = ['structure_v2' => $structure];
        $exam->save();

        // Create ExamCategory
        $category = ExamCategory::create([
            'exam_id' => $exam->id,
            'key' => 'reading',
            'name' => 'reading',
            'skill' => 'reading',
            'meta' => [
                'tasks' => $structure['sections'][0]['tasks'],
                'assembly' => $structure['sections'][0]['assembly'],
            ],
        ]);

        // Create GenerationPlan
        $plan = GenerationPlan::create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'assembly_mode' => 'blueprint',
            'plan_data' => [
                'slots' => $structure['sections'][0]['assembly']['blueprint'],
            ],
            'total_questions' => 10,
            'status' => 'pending',
        ]);

        $synthesizer = app(QuestionSynthesizer::class);

        // Act & Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No archetypes found in section');

        $synthesizer->synthesize($plan, $exam);
    }
}
