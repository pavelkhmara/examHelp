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
 * - inline: Use predefined question placeholders
 */
class AssemblyResolver
{
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

                // Create or update generation plan (using numeric category ID)
                $plan = GenerationPlan::updateOrCreate(
                    [
                        'exam_id' => $exam->id,
                        'section_id' => $category->id, // Use numeric ID instead of string
                    ],
                    [
                        'assembly_mode' => $mode,
                        'plan_data' => $planData['plan_data'],
                        'total_questions' => $planData['total_questions'],
                        'status' => 'pending',
                        'generated_questions' => 0,
                        'error' => null,
                    ]
                );

                $plans[] = $plan;

                Log::info('[AssemblyResolver] Plan created/updated', [
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
        $pick = $assembly['pick'] ?? 0;
        $seed = $assembly['seed'] ?? null;
        $assertions = $assembly['assertions'] ?? [];

        if (!$poolId) {
            throw new \Exception("Pool mode requires pool_id for section {$section['id']}");
        }

        if ($pick <= 0) {
            throw new \Exception("Pool mode requires pick > 0 for section {$section['id']}");
        }

        // Extract total questions from assertions
        $totalQuestions = $assertions['total_tasks_equals'] ?? $pick;

        return [
            'plan_data' => [
                'pool_id' => $poolId,
                'filters' => $filters,
                'pick' => $pick,
                'seed' => $seed,
            ],
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
            $pick = $slot['pick'] ?? 0;
            $totalFromSlots += $pick;

            if ($pick <= 0) {
                throw new \Exception("Blueprint slot requires pick > 0 for section {$section['id']}");
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
     * Inline mode uses predefined question placeholders.
     * Each placeholder represents a single question that will be generated inline.
     *
     * Input:
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
     *     "placeholders": [
     *       { "id": "writing-task-1", "type": "graph_description" },
     *       { "id": "writing-task-2", "type": "essay" }
     *     ]
     *   },
     *   "total_questions": 2
     * }
     */
    public function resolveInline(array $section, array $assembly): array
    {
        // For inline mode, placeholders can be in assembly OR tasks can be in section
        $placeholders = $assembly['placeholders'] ?? [];
        $tasks = $section['tasks'] ?? [];
        $assertions = $assembly['assertions'] ?? [];

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

        if (empty($placeholders)) {
            throw new \Exception("Inline mode requires placeholders in assembly or tasks in section for {$section['id']}");
        }

        // Calculate total questions from placeholders
        $totalQuestions = count($placeholders);

        // Validate assertions
        $expectedTotal = $assertions['total_tasks_equals'] ?? null;
        if ($expectedTotal !== null && $totalQuestions !== $expectedTotal) {
            throw new \Exception(
                "Inline total mismatch for section {$section['id']}: " .
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
