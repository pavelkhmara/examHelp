<?php

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\GenerationPlan;
use App\Models\Question;
use App\Models\QuestionGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Question Group Generation Service
 *
 * Handles creation of QuestionGroup and associated Question records
 * from GenerationPlan data for inline assembly mode with question_groups.
 */
class QuestionGroupGenerationService
{
    /**
     * Generate question groups and questions from GenerationPlan
     *
     * @param  GenerationPlan  $plan  Generation plan with question_groups in plan_data
     * @return array{groups: int, questions: int} Statistics about generated items
     *
     * @throws \Exception If plan_data doesn't contain question_groups or generation fails
     */
    public function generateFromPlan(GenerationPlan $plan): array
    {
        Log::info('[QuestionGroupGenerationService] Starting generation', [
            'plan_id' => $plan->id,
            'exam_id' => $plan->exam_id,
            'section_id' => $plan->section_id,
            'assembly_mode' => $plan->assembly_mode,
        ]);

        // Validate plan has question_groups
        if ($plan->assembly_mode !== 'inline') {
            throw new \Exception("QuestionGroupGenerationService only supports inline assembly mode, got: {$plan->assembly_mode}");
        }

        $questionGroups = $plan->plan_data['question_groups'] ?? null;

        if (!$questionGroups || !is_array($questionGroups)) {
            throw new \Exception('plan_data must contain question_groups array for inline mode');
        }

        $stats = [
            'groups' => 0,
            'questions' => 0,
        ];

        DB::beginTransaction();

        try {
            foreach ($questionGroups as $index => $groupSpec) {
                $group = $this->generateQuestionGroup($plan, $groupSpec, $index);
                $stats['groups']++;
                $stats['questions'] += $group->questions()->count();

                Log::debug('[QuestionGroupGenerationService] Group generated', [
                    'group_id' => $group->id,
                    'group_identifier' => $group->group_id,
                    'questions_count' => $group->questions()->count(),
                ]);
            }

            // Update plan progress
            $plan->update([
                'status' => 'completed',
                'generated_questions' => $stats['questions'],
                'completed_at' => now(),
            ]);

            DB::commit();

            Log::info('[QuestionGroupGenerationService] Generation completed', [
                'plan_id' => $plan->id,
                'groups_generated' => $stats['groups'],
                'questions_generated' => $stats['questions'],
            ]);

            return $stats;
        } catch (\Throwable $e) {
            DB::rollBack();

            // Update plan with error
            $plan->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            Log::error('[QuestionGroupGenerationService] Generation failed', [
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Generate a single question group with its questions
     *
     * @param  GenerationPlan  $plan  Parent generation plan
     * @param  array  $groupSpec  Question group specification from plan_data
     * @param  int  $groupOrder  Order of this group within section
     * @return QuestionGroup Created question group with questions
     *
     * @throws \Exception If validation fails or required fields missing
     */
    public function generateQuestionGroup(GenerationPlan $plan, array $groupSpec, int $groupOrder = 0): QuestionGroup
    {
        // Validate required fields
        if (empty($groupSpec['id'])) {
            throw new \Exception('Question group spec missing required field: id');
        }

        if (empty($groupSpec['title'])) {
            throw new \Exception("Question group '{$groupSpec['id']}' missing required field: title");
        }

        if (!isset($groupSpec['questions']) || !is_array($groupSpec['questions'])) {
            throw new \Exception("Question group '{$groupSpec['id']}' missing questions array");
        }

        Log::debug('[QuestionGroupGenerationService] Creating question group', [
            'group_id' => $groupSpec['id'],
            'title' => $groupSpec['title'],
            'questions_count' => count($groupSpec['questions']),
        ]);

        // Create QuestionGroup
        $questionGroup = QuestionGroup::create([
            'exam_id' => $plan->exam_id,
            'section_id' => $plan->section_id,
            'group_id' => $groupSpec['id'],
            'title' => $groupSpec['title'],
            'instructions' => $groupSpec['instructions'] ?? null,
            'stimulus' => $groupSpec['stimulus'] ?? null,
            'playback_settings' => $groupSpec['playback_settings'] ?? null,
            'metadata' => $groupSpec['metadata'] ?? null,
            'order' => $groupSpec['order'] ?? $groupOrder,
        ]);

        // Generate Questions for this group
        foreach ($groupSpec['questions'] as $questionIndex => $questionSpec) {
            $this->generateQuestion($plan, $questionGroup, $questionSpec, $questionIndex);
        }

        // Validate the group (minimum 2 questions, etc.)
        $questionGroup->validate();

        return $questionGroup;
    }

    /**
     * Generate a single question belonging to a question group
     *
     * @param  GenerationPlan  $plan  Parent generation plan
     * @param  QuestionGroup  $questionGroup  Parent question group
     * @param  array  $questionSpec  Question specification
     * @param  int  $order  Order of this question within group
     * @return Question Created question
     *
     * @throws \Exception If validation fails or required fields missing
     */
    protected function generateQuestion(
        GenerationPlan $plan,
        QuestionGroup $questionGroup,
        array $questionSpec,
        int $order
    ): Question {
        // Validate required fields
        if (empty($questionSpec['id'])) {
            throw new \Exception("Question in group '{$questionGroup->group_id}' missing required field: id");
        }

        if (empty($questionSpec['type'])) {
            throw new \Exception("Question '{$questionSpec['id']}' in group '{$questionGroup->group_id}' missing required field: type");
        }

        // Generate unique question_id with group prefix to avoid collisions
        // e.g., "listening-task-1_q1" instead of just "q1"
        $uniqueQuestionId = "{$questionGroup->group_id}_{$questionSpec['id']}";

        Log::debug('[QuestionGroupGenerationService] Creating question', [
            'question_id' => $uniqueQuestionId,
            'raw_id' => $questionSpec['id'],
            'type' => $questionSpec['type'],
            'group_id' => $questionGroup->group_id,
            'order' => $order,
        ]);

        // Prepare question data (v2 archetype structure)
        // Use empty arrays [] for JSON fields to avoid NOT NULL constraint violations
        $questionData = [
            'exam_id' => $plan->exam_id,
            'section_id' => $plan->section_id,
            'question_group_id' => $questionGroup->id,
            'question_id' => $uniqueQuestionId,
            'type' => $questionSpec['type'],

            // v2 archetype fields (use [] for JSON, 0 for integers)
            'skills_measured' => $questionSpec['skills_measured'] ?? [],
            'time_limit_sec' => $questionSpec['time_limit_sec'] ?? 0,
            'instructions' => $questionSpec['instructions'] ?? [],
            'stimulus' => $questionSpec['stimulus'] ?? [],
            'interaction' => $questionSpec['interaction'] ?? [],
            'response' => $questionSpec['response'] ?? [],
            'scoring' => $questionSpec['scoring'] ?? [],
            'metadata' => $questionSpec['metadata'] ?? [],

            // Optional v2 fields (use [] for JSON)
            'constraints' => $questionSpec['constraints'] ?? [],
            'randomization' => $questionSpec['randomization'] ?? [],
            'outcome_reporting' => $questionSpec['outcome_reporting'] ?? [],
            'io_signature' => $questionSpec['io_signature'] ?? [],
            'typical_errors' => $questionSpec['typical_errors'] ?? [],
            'ui_hints' => $questionSpec['ui_hints'] ?? [],
            'accessibility' => $questionSpec['accessibility'] ?? [],

            // Note: 'order' is not in Question fillable, will be handled separately if needed
        ];

        return Question::create($questionData);
    }

    /**
     * Delete all question groups and questions for a given plan
     * (useful for regeneration or cleanup)
     *
     * @param  GenerationPlan  $plan  Plan to clean up
     * @return int Number of question groups deleted (questions cascade)
     */
    public function deleteGeneratedContent(GenerationPlan $plan): int
    {
        Log::info('[QuestionGroupGenerationService] Deleting generated content', [
            'plan_id' => $plan->id,
            'exam_id' => $plan->exam_id,
            'section_id' => $plan->section_id,
        ]);

        $count = QuestionGroup::where('exam_id', $plan->exam_id)
            ->where('section_id', $plan->section_id)
            ->delete();

        Log::info('[QuestionGroupGenerationService] Content deleted', [
            'plan_id' => $plan->id,
            'groups_deleted' => $count,
        ]);

        return $count;
    }

    /**
     * Get statistics about generated content for a plan
     *
     * @param  GenerationPlan  $plan  Plan to get stats for
     * @return array{groups: int, questions: int}
     */
    public function getGenerationStats(GenerationPlan $plan): array
    {
        $groupsCount = QuestionGroup::where('exam_id', $plan->exam_id)
            ->where('section_id', $plan->section_id)
            ->count();

        $questionsCount = Question::where('exam_id', $plan->exam_id)
            ->where('section_id', $plan->section_id)
            ->whereNotNull('question_group_id')
            ->count();

        return [
            'groups' => $groupsCount,
            'questions' => $questionsCount,
        ];
    }
}
