<?php

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\Question;
use App\Models\QuestionGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Materializes structure_v2 (from meta) into database tables.
 *
 * Converts JSON structure into:
 * - ExamCategory records
 * - QuestionGroup records (from assembly.question_groups) - structure only, no content
 *
 * NOTE: Question records are NOT created here for inline mode.
 * Questions are created by QuestionAttacher after AI synthesis generates full content.
 * This prevents empty skeleton questions from being created before synthesis.
 *
 * For fixture imports (with full question data), use createQuestionsInGroup=true.
 */
class StructureMaterializer
{
    /**
     * Materialize structure_v2 sections into database tables
     *
     * @param Exam $exam
     * @param array $sections Array of section data from structure_v2
     * @param bool $createQuestionsInGroup If true, create Question records from question_groups
     *                                     (for fixture imports with full question data)
     *                                     If false (default), only create QuestionGroup structure
     *                                     (for pipeline - synthesis will create Questions later)
     * @return array Stats about created records
     */
    public function materialize(Exam $exam, array $sections, bool $createQuestionsInGroup = false): array
    {
        $stats = [
            'categories' => 0,
            'question_groups' => 0,
            'questions' => 0,
        ];

        DB::transaction(function () use ($exam, $sections, $createQuestionsInGroup, &$stats) {
            // Delete existing data (fresh start)
            Question::where('exam_id', $exam->id)->delete();
            QuestionGroup::where('exam_id', $exam->id)->delete();
            ExamCategory::where('exam_id', $exam->id)->delete();

            $sectionOrder = 0;

            foreach ($sections as $section) {
                $sectionOrder++;
                $sectionId = $section['id'] ?? $section['key'] ?? "section_{$sectionOrder}";

                // Create ExamCategory
                // Support both 'name' (fixture format) and 'title' (generated format)
                $category = ExamCategory::create([
                    'exam_id' => $exam->id,
                    'key' => $sectionId,
                    'name' => $section['name'] ?? $section['title'] ?? $sectionId,
                    'skill' => $section['skill'] ?? null,
                    'duration_min' => $section['duration_min'] ?? null,
                    'max_score' => $section['max_score'] ?? null,
                    'min_pass_percent' => $section['min_pass_percent'] ?? null,
                    'description' => $section['description'] ?? null,
                    'order' => $sectionOrder,
                    'meta' => [
                        'questions' => $section['questions'] ?? [],
                        'assembly' => $section['assembly'] ?? null,
                        'question_archetypes' => $section['question_archetypes'] ?? [],
                    ],
                ]);

                $stats['categories']++;

                // Process question_groups from assembly
                $assembly = $section['assembly'] ?? [];
                $questionGroups = $assembly['question_groups'] ?? [];

                if (empty($questionGroups)) {
                    continue;
                }

                $groupOrder = 0;
                foreach ($questionGroups as $groupData) {
                    $groupOrder++;

                    // Create QuestionGroup (structure only)
                    $group = QuestionGroup::create([
                        'exam_id' => $exam->id,
                        'section_id' => $category->id,
                        'group_id' => $groupData['id'] ?? "group_{$sectionOrder}_{$groupOrder}",
                        'title' => $groupData['title'] ?? null,
                        'order' => $groupData['order'] ?? $groupOrder,
                        'instructions' => $groupData['instructions'] ?? null,
                        'stimulus' => $groupData['stimulus'] ?? null,
                        'playback_settings' => $groupData['playback_settings'] ?? null,
                        'metadata' => $groupData['metadata'] ?? null,
                    ]);

                    $stats['question_groups']++;

                    // Only create Question records if explicitly requested (fixture import)
                    // For pipeline flow, Questions are created by QuestionAttacher after synthesis
                    if (!$createQuestionsInGroup) {
                        continue;
                    }

                    // Check if questions have actual content (not just placeholders)
                    $questions = $groupData['questions'] ?? [];
                    $hasContent = !empty($questions) && $this->questionsHaveContent($questions);

                    if (!$hasContent) {
                        Log::debug('[StructureMaterializer] Skipping question creation - no content', [
                            'group_id' => $group->group_id,
                            'questions_count' => count($questions),
                        ]);
                        continue;
                    }

                    // Create Questions with full content (fixture import path)
                    $questionOrder = 0;
                    $groupIdPrefix = $groupData['id'] ?? "group_{$sectionOrder}_{$groupOrder}";

                    foreach ($questions as $qData) {
                        $questionOrder++;

                        // Generate unique question_id with group prefix to avoid collisions
                        $rawQuestionId = $qData['id'] ?? $qData['question_id'] ?? "q{$questionOrder}";
                        $uniqueQuestionId = "{$groupIdPrefix}_{$rawQuestionId}";

                        Question::create([
                            'exam_id' => $exam->id,
                            'section_id' => $category->id,
                            'question_group_id' => $group->id,
                            'question_id' => $uniqueQuestionId,
                            'type' => $qData['type'] ?? 'unknown',
                            'order' => $qData['order'] ?? $questionOrder,
                            'skills_measured' => $qData['skills_measured'] ?? [],
                            'time_limit_sec' => $qData['time_limit_sec'] ?? 0,
                            'instructions' => $qData['instructions'] ?? [],
                            'stimulus' => $qData['stimulus'] ?? [],
                            'interaction' => $qData['interaction'] ?? [],
                            'response' => $qData['response'] ?? [],
                            'scoring' => $qData['scoring'] ?? [],
                            'metadata' => $qData['metadata'] ?? [],
                            'constraints' => $qData['constraints'] ?? [],
                        ]);

                        $stats['questions']++;
                    }
                }
            }
        });

        Log::info('Structure materialized to database', [
            'exam_id' => $exam->id,
            'stats' => $stats,
            'create_questions' => $createQuestionsInGroup,
        ]);

        return $stats;
    }

    /**
     * Check if questions array contains actual content (not just type/id placeholders)
     *
     * @param array $questions
     * @return bool
     */
    protected function questionsHaveContent(array $questions): bool
    {
        if (empty($questions)) {
            return false;
        }

        // Check first question for content indicators
        $first = $questions[0];

        // Questions with content have instructions, interaction, or stimulus with actual data
        $hasInstructions = !empty($first['instructions']) && (
            !empty($first['instructions']['text_html']) ||
            !empty($first['instructions']['brief']) ||
            !empty($first['instructions']['full'])
        );

        $hasInteraction = !empty($first['interaction']) && (
            !empty($first['interaction']['options']) ||
            !empty($first['interaction']['pairs']) ||
            !empty($first['interaction']['spans'])
        );

        $hasStimulus = !empty($first['stimulus']) && (
            !empty($first['stimulus']['text_html']) ||
            !empty($first['stimulus']['audio']) ||
            !empty($first['stimulus']['images'])
        );

        return $hasInstructions || $hasInteraction || $hasStimulus;
    }
}
