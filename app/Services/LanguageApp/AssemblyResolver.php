<?php

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\GenerationPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Assembly Resolver Service
 *
 * Resolves assembly configurations from Phase B output into concrete generation plans.
 * Creates GenerationPlan records for each exam section based on assembly mode.
 *
 * Supports three assembly modes:
 * - pool: Generate questions from a pool with filters
 * - blueprint: Generate questions using multi-slot blueprint
 * - inline: Use predefined question placeholders (supports question_groups)
 */
class AssemblyResolver
{
    protected QuestionGroupAssembler $questionGroupAssembler;

    public function __construct(QuestionGroupAssembler $questionGroupAssembler)
    {
        $this->questionGroupAssembler = $questionGroupAssembler;
    }
    /**
     * Resolve all sections from Phase B structure and create generation plans
     *
     * @param Exam $exam Exam with structure_v2 in meta
     * @return array<GenerationPlan> Array of created generation plans
     * @throws \Exception If structure is invalid or assembly configs are missing
     */
    public function resolve(Exam $exam): array
    {
        Log::info('[AssemblyResolver] Starting resolution', [
            'exam_id' => $exam->id,
            'exam_title' => $exam->title,
        ]);

        // Get structure_v2 from exam meta
        $structure = $exam->meta['structure_v2'] ?? null;

        if (!$structure) {
            throw new \Exception('structure_v2 not found in exam meta. Run Phase B first.');
        }

        $sections = $structure['sections'] ?? [];

        if (empty($sections)) {
            throw new \Exception('No sections found in structure_v2');
        }

        $plans = [];

        DB::beginTransaction();

        try {
            // Delete all old generation plans for this exam before creating new ones
            // This ensures we only have plans from the current assembly resolution
            // Note: Plans are also cascade-deleted when ExamCategory is deleted in createCategories()
            $deletedPlansCount = \App\Models\GenerationPlan::where('exam_id', $exam->id)->delete();

            Log::info('[AssemblyResolver] Deleted old generation plans', [
                'exam_id' => $exam->id,
                'deleted_plans_count' => $deletedPlansCount,
            ]);

            foreach ($sections as $section) {
                $sectionId = $section['id'] ?? null;
                $assembly = $section['assembly'] ?? null;

                if (!$sectionId) {
                    Log::warning('[AssemblyResolver] Section missing id, skipping', [
                        'section' => $section,
                    ]);
                    continue;
                }

                if (!$assembly || !isset($assembly['mode'])) {
                    throw new \Exception("Section {$sectionId} missing assembly configuration");
                }

                $mode = $assembly['mode'];

                Log::info('[AssemblyResolver] Resolving section', [
                    'section_id' => $sectionId,
                    'assembly_mode' => $mode,
                ]);

                // Resolve based on mode
                $planData = match ($mode) {
                    'pool' => $this->resolvePool($section, $assembly),
                    'blueprint' => $this->resolveBlueprint($section, $assembly),
                    'inline' => $this->resolveInline($section, $assembly),
                    default => throw new \Exception("Unknown assembly mode: {$mode}"),
                };

                // Look up ExamCategory by skill (more reliable than key matching)
                $skill = $section['skill'] ?? null;

                if (!$skill) {
                    throw new \Exception("Section {$sectionId} has no 'skill' field. Cannot match to ExamCategory.");
                }

                $category = ExamCategory::where('exam_id', $exam->id)
                    ->where('skill', $skill)
                    ->first();

                if (!$category) {
                    throw new \Exception("ExamCategory not found for skill '{$skill}' (section {$sectionId}). Ensure categories are created before resolving assembly.");
                }

                // Create generation plan (old plans already deleted above)
                $plan = GenerationPlan::create([
                    'exam_id' => $exam->id,
                    'section_id' => $category->id, // Use numeric ID instead of string
                    'assembly_mode' => $mode,
                    'plan_data' => $planData['plan_data'],
                    'total_questions' => $planData['total_questions'],
                    'status' => 'pending',
                    'generated_questions' => 0,
                    'error' => null,
                ]);

                $plans[] = $plan;

                Log::info('[AssemblyResolver] Plan created', [
                    'plan_id' => $plan->id,
                    'section_id' => $sectionId,
                    'assembly_mode' => $mode,
                    'total_questions' => $planData['total_questions'],
                ]);
            }

            DB::commit();

            Log::info('[AssemblyResolver] Resolution completed', [
                'exam_id' => $exam->id,
                'plans_count' => count($plans),
            ]);

            return $plans;
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[AssemblyResolver] Resolution failed', [
                'exam_id' => $exam->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Resolve pool assembly mode
     *
     * Pool mode generates questions from a single pool with filters.
     *
     * Input:
     * {
     *   "mode": "pool",
     *   "pool_id": "listening-mcq-pool",
     *   "filters": { "type": ["single_select"], "difficulty": "medium" },
     *   "pick": 40,
     *   "seed": "exam-123-listening",
     *   "assertions": { "total_tasks_equals": 40 }
     * }
     *
     * Output:
     * {
     *   "plan_data": {
     *     "pool_id": "listening-mcq-pool",
     *     "filters": { "type": ["single_select"], "difficulty": "medium" },
     *     "pick": 40,
     *     "seed": "exam-123-listening"
     *   },
     *   "total_questions": 40
     * }
     */
    public function resolvePool(array $section, array $assembly): array
    {
        $poolId = $assembly['pool_id'] ?? null;
        $filters = $assembly['filters'] ?? [];

        // Support both pick (old) and questions_count (new) for backward compatibility
        $questionsCount = $assembly['questions_count'] ?? $assembly['pick'] ?? 0;
        $pick = $questionsCount; // Keep $pick for backward compatibility in plan_data

        $seed = $assembly['seed'] ?? null;
        $assertions = $assembly['assertions'] ?? [];

        if (!$poolId) {
            throw new \Exception("Pool mode requires pool_id for section {$section['id']}");
        }

        if ($questionsCount <= 0) {
            throw new \Exception("Pool mode requires questions_count (or pick) > 0 for section {$section['id']}");
        }

        // Extract total questions from assertions
        $totalQuestions = $assertions['total_tasks_equals'] ?? $questionsCount;

        // Build plan_data with new fields (Phase 1-2: explicit stimulus structure)
        $planData = [
            'pool_id' => $poolId,
            'filters' => $filters,
            'pick' => $pick,
            'seed' => $seed,
        ];

        // Add new fields if present (optional for backward compatibility)
        if (isset($assembly['questions_count'])) {
            $planData['questions_count'] = $assembly['questions_count'];
        }
        if (isset($assembly['shared_stimulus'])) {
            $planData['shared_stimulus'] = $assembly['shared_stimulus'];
        }
        if (isset($assembly['stimulus_type'])) {
            $planData['stimulus_type'] = $assembly['stimulus_type'];
        }

        return [
            'plan_data' => $planData,
            'total_questions' => $totalQuestions,
        ];
    }

    /**
     * Resolve blueprint assembly mode
     *
     * Blueprint mode generates questions using multi-slot blueprint.
     * Each slot defines a subset of questions with specific filters.
     *
     * Input:
     * {
     *   "mode": "blueprint",
     *   "blueprint": [
     *     {
     *       "slot": "slot-1",
     *       "from_pool": "listening-pool",
     *       "pick": 10,
     *       "filters": { "type": ["single_select"] }
     *     },
     *     {
     *       "slot": "slot-2",
     *       "from_pool": "listening-pool",
     *       "pick": 10,
     *       "filters": { "type": ["multi_select"] }
     *     }
     *   ],
     *   "assertions": { "total_tasks_equals": 20 }
     * }
     *
     * Output:
     * {
     *   "plan_data": {
     *     "slots": [
     *       {
     *         "slot": "slot-1",
     *         "from_pool": "listening-pool",
     *         "pick": 10,
     *         "filters": { "type": ["single_select"] }
     *       },
     *       {
     *         "slot": "slot-2",
     *         "from_pool": "listening-pool",
     *         "pick": 10,
     *         "filters": { "type": ["multi_select"] }
     *       }
     *     ]
     *   },
     *   "total_questions": 20
     * }
     */
    public function resolveBlueprint(array $section, array $assembly): array
    {
        $blueprint = $assembly['blueprint'] ?? [];
        $assertions = $assembly['assertions'] ?? [];

        if (empty($blueprint)) {
            throw new \Exception("Blueprint mode requires blueprint array for section {$section['id']}");
        }

        // Calculate total questions from slots
        $totalFromSlots = 0;
        foreach ($blueprint as $slot) {
            // Support both pick (old) and questions_count (new) for backward compatibility
            $questionsCount = $slot['questions_count'] ?? $slot['pick'] ?? 0;
            $totalFromSlots += $questionsCount;

            if ($questionsCount <= 0) {
                throw new \Exception("Blueprint slot requires questions_count (or pick) > 0 for section {$section['id']}");
            }
        }

        // Validate assertions
        $expectedTotal = $assertions['total_tasks_equals'] ?? null;
        if ($expectedTotal !== null && $totalFromSlots !== $expectedTotal) {
            throw new \Exception(
                "Blueprint total mismatch for section {$section['id']}: " .
                "slots sum to {$totalFromSlots}, but assertions expect {$expectedTotal}"
            );
        }

        return [
            'plan_data' => [
                'slots' => $blueprint,
            ],
            'total_questions' => $totalFromSlots,
        ];
    }

    /**
     * Resolve inline assembly mode
     *
     * Inline mode supports two formats:
     * 1. Question groups (NEW) - multiple questions sharing common stimulus
     * 2. Placeholders (LEGACY) - individual question placeholders
     *
     * Input with question_groups:
     * {
     *   "mode": "inline",
     *   "question_groups": [
     *     {
     *       "id": "listening-group-1",
     *       "title": "Zadanie I",
     *       "stimulus": { "audio": ["https://example.com/audio.mp3"] },
     *       "playback_settings": { "max_plays": 1, "enforcement": "strict" },
     *       "questions": [
     *         { "id": "q1", "type": "single_select", ... },
     *         { "id": "q2", "type": "single_select", ... }
     *       ]
     *     }
     *   ],
     *   "assertions": { "total_tasks_equals": 2 }
     * }
     *
     * Input with placeholders (legacy):
     * {
     *   "mode": "inline",
     *   "placeholders": [
     *     { "id": "writing-task-1", "type": "graph_description" },
     *     { "id": "writing-task-2", "type": "essay" }
     *   ],
     *   "assertions": { "total_tasks_equals": 2 }
     * }
     *
     * Output:
     * {
     *   "plan_data": {
     *     "question_groups": [ ... ] OR "placeholders": [ ... ]
     *   },
     *   "total_questions": 2
     * }
     */
    public function resolveInline(array $section, array $assembly): array
    {
        $sectionId = $section['id'] ?? 'unknown';
        $assertions = $assembly['assertions'] ?? [];

        // NEW: Check for question_groups (priority over placeholders)
        if (isset($assembly['question_groups']) && !empty($assembly['question_groups'])) {
            Log::info('[AssemblyResolver] Using question_groups for inline mode', [
                'section_id' => $sectionId,
                'groups_count' => count($assembly['question_groups']),
            ]);

            // Use QuestionGroupAssembler to process groups
            $result = $this->questionGroupAssembler->assemble(
                $assembly['question_groups'],
                $sectionId
            );

            // Validate assertions if present
            $expectedTotal = $assertions['total_tasks_equals'] ?? null;
            if ($expectedTotal !== null && $result['total_questions'] !== $expectedTotal) {
                throw new \Exception(
                    "Question groups total mismatch for section {$sectionId}: " .
                    "{$result['total_questions']} questions, but assertions expect {$expectedTotal}"
                );
            }

            return $result;
        }

        // LEGACY: Fall back to placeholders/tasks (existing behavior)
        Log::info('[AssemblyResolver] Using placeholders for inline mode (legacy)', [
            'section_id' => $sectionId,
        ]);

        $placeholders = $assembly['placeholders'] ?? [];
        $tasks = $section['tasks'] ?? [];

        // If placeholders not in assembly, use tasks from section
        if (empty($placeholders) && !empty($tasks)) {
            // Convert tasks to placeholders format
            $placeholders = array_map(function ($task, $index) {
                return [
                    'id' => $task['id'] ?? 'task_' . ($index + 1),
                    'type' => $task['type'] ?? 'inline_task',
                    'spec' => $task,
                ];
            }, $tasks, array_keys($tasks));
        }

        // FALLBACK: If still no placeholders, try to create from question_archetypes
        if (empty($placeholders)) {
            $questionArchetypes = $section['question_archetypes'] ?? [];

            if (!empty($questionArchetypes)) {
                Log::info('[AssemblyResolver] Creating placeholders from question_archetypes (fallback)', [
                    'section_id' => $sectionId,
                    'archetypes_count' => count($questionArchetypes),
                ]);

                // Convert archetypes to placeholders
                $placeholders = array_map(function ($archetype, $index) {
                    return [
                        'id' => $archetype['id'] ?? 'archetype_' . ($index + 1),
                        'type' => $archetype['type'] ?? 'unknown',
                        'archetype' => $archetype,
                    ];
                }, $questionArchetypes, array_keys($questionArchetypes));
            }
        }

        if (empty($placeholders)) {
            throw new \Exception("Inline mode requires question_groups or placeholders in assembly or tasks in section for {$sectionId}");
        }

        // Calculate total questions from placeholders
        $totalQuestions = count($placeholders);

        // Validate assertions
        $expectedTotal = $assertions['total_tasks_equals'] ?? null;
        if ($expectedTotal !== null && $totalQuestions !== $expectedTotal) {
            throw new \Exception(
                "Inline total mismatch for section {$sectionId}: " .
                "{$totalQuestions} placeholders, but assertions expect {$expectedTotal}"
            );
        }

        return [
            'plan_data' => [
                'placeholders' => $placeholders,
            ],
            'total_questions' => $totalQuestions,
        ];
    }
}
