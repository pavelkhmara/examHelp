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
 * - QuestionGroup records (from assembly.question_groups)
 * - Question records
 */
class StructureMaterializer
{
    /**
     * Materialize structure_v2 sections into database tables
     *
     * @param Exam $exam
     * @param array $sections Array of section data from structure_v2
     * @return array Stats about created records
     */
    public function materialize(Exam $exam, array $sections): array
    {
        $stats = [
            'categories' => 0,
            'question_groups' => 0,
            'questions' => 0,
        ];

        DB::transaction(function () use ($exam, $sections, &$stats) {
            // Delete existing data (fresh start)
            Question::where('exam_id', $exam->id)->delete();
            QuestionGroup::where('exam_id', $exam->id)->delete();
            ExamCategory::where('exam_id', $exam->id)->delete();

            $sectionOrder = 0;

            foreach ($sections as $section) {
                $sectionOrder++;
                $sectionId = $section['id'] ?? $section['key'] ?? "section_{$sectionOrder}";

                // Create ExamCategory
                $category = ExamCategory::create([
                    'exam_id' => $exam->id,
                    'key' => $sectionId,
                    'name' => $section['name'] ?? $sectionId,
                    'skill' => $section['skill'] ?? null,
                    'duration_min' => $section['duration_min'] ?? null,
                    'max_score' => $section['max_score'] ?? null,
                    'min_pass_percent' => $section['min_pass_percent'] ?? null,
                    'description' => $section['description'] ?? null,
                    'order' => $sectionOrder,
                    'meta' => [
                        'tasks' => $section['tasks'] ?? [],
                        'assembly' => $section['assembly'] ?? null,
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

                    // Create QuestionGroup
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

                    // Create Questions in group
                    $questions = $groupData['questions'] ?? [];
                    $questionOrder = 0;

                    foreach ($questions as $qData) {
                        $questionOrder++;

                        Question::create([
                            'exam_id' => $exam->id,
                            'section_id' => $category->id,
                            'question_group_id' => $group->id,
                            'question_id' => $qData['id'] ?? "q_{$sectionOrder}_{$groupOrder}_{$questionOrder}",
                            'type' => $qData['type'] ?? 'unknown',
                            'order' => $qData['order'] ?? $questionOrder,
                            'instructions' => $qData['instructions'] ?? [],
                            'stimulus' => $qData['stimulus'] ?? [],
                            'interaction' => $qData['interaction'] ?? [],
                            'scoring' => $qData['scoring'] ?? [],
                            'metadata' => $qData['metadata'] ?? [],
                        ]);

                        $stats['questions']++;
                    }
                }
            }
        });

        Log::info('Structure materialized to database', [
            'exam_id' => $exam->id,
            'stats' => $stats,
        ]);

        return $stats;
    }
}
