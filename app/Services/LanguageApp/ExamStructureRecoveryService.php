<?php

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\ExamExampleQuestion;
use Illuminate\Support\Facades\Log;

/**
 * Service for recovering missing exam_structure data
 *
 * Problem: Sometimes exam.meta['exam_structure'] is empty or incomplete,
 * even though ExamCategory and ExamExampleQuestion records exist.
 *
 * This service reconstructs:
 * - exam.meta['exam_structure']['question_archetypes'] from category.meta['question_archetypes']
 * - exam.meta['exam_structure']['section_archetypes'] from categories
 *
 * Usage:
 * - Call manually: ExamStructureRecoveryService::recover($exam)
 * - Via Artisan: php artisan exam:recover-structure {examId}
 */
class ExamStructureRecoveryService
{
    /**
     * Check if exam structure needs recovery
     *
     * @return bool True if recovery needed
     */
    public function needsRecovery(Exam $exam): bool
    {
        $examStructure = $exam->meta['exam_structure'] ?? [];
        $questionArchetypes = $examStructure['question_archetypes'] ?? [];
        $sectionArchetypes = $examStructure['section_archetypes'] ?? [];

        // Check if we have categories but no structure
        $hasCategories = $exam->categories()->exists();
        $hasQuestions = ExamExampleQuestion::where('exam_id', $exam->id)->exists();

        $hasMissingArchetypes = empty($questionArchetypes);
        $hasMissingSections = empty($sectionArchetypes);

        return $hasCategories && ($hasMissingArchetypes || $hasMissingSections);
    }

    /**
     * Recover exam structure from existing data
     *
     * @param  bool  $dryRun  If true, don't save changes
     * @return array Recovery result with stats
     */
    public function recover(Exam $exam, bool $dryRun = false): array
    {
        Log::info('ExamStructureRecovery: Starting recovery', [
            'exam_id' => $exam->id,
            'dry_run' => $dryRun,
        ]);

        $result = [
            'exam_id' => $exam->id,
            'needed_recovery' => false,
            'recovered_archetypes' => 0,
            'recovered_sections' => 0,
            'errors' => [],
            'dry_run' => $dryRun,
        ];

        // Get current structure
        $examStructure = $exam->meta['exam_structure'] ?? [];
        $existingArchetypes = $examStructure['question_archetypes'] ?? [];
        $existingSections = $examStructure['section_archetypes'] ?? [];

        // Load categories with relationships
        $categories = $exam->categories()
            ->orderBy('order')
            ->get();

        if ($categories->isEmpty()) {
            $result['errors'][] = 'No categories found for this exam';

            return $result;
        }

        // Recover question_archetypes
        $recoveredArchetypes = $this->recoverQuestionArchetypes($categories, $exam);
        $archetypesChanged = ! empty($recoveredArchetypes) && $recoveredArchetypes !== $existingArchetypes;

        // Recover section_archetypes
        $recoveredSections = $this->recoverSectionArchetypes($categories);
        $sectionsChanged = ! empty($recoveredSections) && $recoveredSections !== $existingSections;

        if ($archetypesChanged || $sectionsChanged) {
            $result['needed_recovery'] = true;
            $result['recovered_archetypes'] = count($recoveredArchetypes);
            $result['recovered_sections'] = count($recoveredSections);

            // Build new structure
            $newStructure = array_merge($examStructure, [
                'question_archetypes' => $recoveredArchetypes,
                'section_archetypes' => $recoveredSections,
            ]);

            // Preserve existing fields
            if (! isset($newStructure['sources'])) {
                $newStructure['sources'] = $exam->sources ?? [];
            }

            if (! $dryRun) {
                // Save to database
                $meta = $exam->meta ?? [];
                $meta['exam_structure'] = $newStructure;
                $exam->meta = $meta;
                $exam->save();

                Log::info('ExamStructureRecovery: Structure recovered and saved', [
                    'exam_id' => $exam->id,
                    'archetypes_count' => count($recoveredArchetypes),
                    'sections_count' => count($recoveredSections),
                ]);
            } else {
                Log::info('ExamStructureRecovery: Dry run - changes not saved', [
                    'exam_id' => $exam->id,
                    'archetypes_count' => count($recoveredArchetypes),
                    'sections_count' => count($recoveredSections),
                ]);
            }

            $result['recovered_structure'] = $newStructure;
        } else {
            Log::info('ExamStructureRecovery: No recovery needed', [
                'exam_id' => $exam->id,
            ]);
        }

        return $result;
    }

    /**
     * Recover question_archetypes from categories and example questions
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\ExamCategory>  $categories
     */
    protected function recoverQuestionArchetypes($categories, Exam $exam): array
    {
        $allArchetypes = [];

        // Strategy 1: Get from category.meta['question_archetypes']
        foreach ($categories as $category) {
            $categoryArchetypes = $category->meta['question_archetypes'] ?? [];
            if (! empty($categoryArchetypes)) {
                $allArchetypes = array_merge($allArchetypes, $categoryArchetypes);
            }
        }

        // Strategy 2: If still empty, reconstruct from category.meta['steps']
        if (empty($allArchetypes)) {
            foreach ($categories as $category) {
                $steps = $category->meta['steps'] ?? [];
                foreach ($steps as $step) {
                    $allArchetypes[] = [
                        'id' => $step['archetype_id'] ?? 'archetype_'.\Str::random(8),
                        'name' => $step['name'] ?? 'Unknown Task',
                        'category' => $category->key,
                        'step_duration' => $step['duration_min'] ?? null,
                        'difficulty' => $step['difficulty'] ?? null,
                        'distractors' => $step['distractors'] ?? [],
                        'stem_templates' => $step['stem_templates'] ?? [],
                        'ranges' => $step['ranges'] ?? null,
                        'evidence' => $step['evidence'] ?? [],
                        'category_weights' => [$category->key => 1.0],
                        'recovered' => true,
                        'recovery_source' => 'category_steps',
                    ];
                }
            }
        }

        // Strategy 3: If still empty, extract from ExamExampleQuestion
        if (empty($allArchetypes)) {
            $examples = ExamExampleQuestion::where('exam_id', $exam->id)->get();
            $archetypesByCategory = [];

            foreach ($examples as $example) {
                $categoryKey = $example->examCategory->key ?? 'unknown';
                if (! isset($archetypesByCategory[$categoryKey])) {
                    $archetypesByCategory[$categoryKey] = [];
                }

                $archetypeId = $example->archetype_id ?? 'archetype_'.\Str::random(8);

                // Avoid duplicates
                if (! isset($archetypesByCategory[$categoryKey][$archetypeId])) {
                    $archetypesByCategory[$categoryKey][$archetypeId] = [
                        'id' => $archetypeId,
                        'name' => $example->name ?? 'Unknown Task',
                        'category' => $categoryKey,
                        'task_type' => $example->task_type ?? null,
                        'step_duration' => null,
                        'difficulty' => null,
                        'category_weights' => [$categoryKey => 1.0],
                        'recovered' => true,
                        'recovery_source' => 'example_questions',
                    ];
                }
            }

            foreach ($archetypesByCategory as $archetypes) {
                $allArchetypes = array_merge($allArchetypes, array_values($archetypes));
            }
        }

        // Add recovery metadata
        foreach ($allArchetypes as &$archetype) {
            if (! isset($archetype['recovered'])) {
                $archetype['recovered'] = false;
            }
        }

        return $allArchetypes;
    }

    /**
     * Recover section_archetypes from categories
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\ExamCategory>  $categories
     */
    protected function recoverSectionArchetypes($categories): array
    {
        $sections = [];

        foreach ($categories as $category) {
            $sections[] = [
                'section' => $category->key,
                'name' => $category->name,
                'description' => $category->description,
                'order' => $category->order,
                'archetype_count' => $category->meta['archetype_count'] ?? 0,
                'sum_weight' => $category->meta['sum_weight'] ?? 0.0,
                'recovered' => true,
            ];
        }

        return $sections;
    }

    /**
     * Batch recover multiple exams
     */
    public function recoverBatch(array $examIds, bool $dryRun = false): array
    {
        $results = [
            'total' => count($examIds),
            'recovered' => 0,
            'skipped' => 0,
            'failed' => 0,
            'details' => [],
        ];

        foreach ($examIds as $examId) {
            $exam = Exam::find($examId);

            if (! $exam) {
                $results['failed']++;
                $results['details'][$examId] = ['error' => 'Exam not found'];

                continue;
            }

            try {
                $result = $this->recover($exam, $dryRun);

                if ($result['needed_recovery']) {
                    $results['recovered']++;
                } else {
                    $results['skipped']++;
                }

                $results['details'][$examId] = $result;
            } catch (\Throwable $e) {
                $results['failed']++;
                $results['details'][$examId] = [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ];

                Log::error('ExamStructureRecovery: Failed to recover exam', [
                    'exam_id' => $examId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Get diagnostics for an exam
     */
    public function diagnose(Exam $exam): array
    {
        $examStructure = $exam->meta['exam_structure'] ?? [];
        $questionArchetypes = $examStructure['question_archetypes'] ?? [];
        $sectionArchetypes = $examStructure['section_archetypes'] ?? [];

        $categories = $exam->categories()->get();
        $exampleQuestions = ExamExampleQuestion::where('exam_id', $exam->id)->get();

        $diagnostics = [
            'exam_id' => $exam->id,
            'exam_title' => $exam->title,
            'needs_recovery' => $this->needsRecovery($exam),
            'current_state' => [
                'has_exam_structure' => ! empty($examStructure),
                'question_archetypes_count' => count($questionArchetypes),
                'section_archetypes_count' => count($sectionArchetypes),
                'categories_count' => $categories->count(),
                'example_questions_count' => $exampleQuestions->count(),
            ],
            'categories' => [],
        ];

        foreach ($categories as $category) {
            $categoryArchetypes = $category->meta['question_archetypes'] ?? [];
            $categorySteps = $category->meta['steps'] ?? [];

            $diagnostics['categories'][] = [
                'key' => $category->key,
                'name' => $category->name,
                'has_question_archetypes' => ! empty($categoryArchetypes),
                'has_steps' => ! empty($categorySteps),
                'question_archetypes_count' => count($categoryArchetypes),
                'steps_count' => count($categorySteps),
                'example_questions_count' => $exampleQuestions->where('exam_category_id', $category->id)->count(),
            ];
        }

        return $diagnostics;
    }
}
