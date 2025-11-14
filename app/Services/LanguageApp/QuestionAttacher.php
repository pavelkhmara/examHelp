<?php

declare(strict_types=1);

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\GenerationPlan;
use Illuminate\Support\Facades\DB;

class QuestionAttacher
{
    /**
     * @param array<int, array<string, mixed>> $questions
     */
    public function attachToExam(array $questions, GenerationPlan $plan, Exam $exam): array
    {
        if (empty($questions)) {
            return [];
        }

        DB::transaction(function () use ($questions, $plan, $exam) {
            $questionIds = array_values(array_map(
                fn (array $question) => $question['id'] ?? null,
                $questions,
            ));

            $questionIds = array_values(array_filter($questionIds, fn ($id) => is_string($id) && $id !== ''));

            // Update exam meta with generated questions
            $meta = $exam->meta ?? [];
            $existingQuestions = $meta['generated_questions_v2'] ?? [];

            $meta['generated_questions_v2'] = array_values(array_merge(
                $existingQuestions,
                $questions,
            ));
            $attachedQuestions = $meta['generated_questions_v2'];

            // Update structure_v2 sections with question IDs
            $structure = $meta['structure_v2'] ?? [];
            $sections = $structure['sections'] ?? [];
            $sections = $this->updateSectionStructure(
                $sections,
                $plan,
                $questionIds,
            );

            $structure['sections'] = $sections;
            $meta['structure_v2'] = $structure;

            $exam->meta = $meta;
            $exam->save();

            // Update corresponding ExamCategory meta with question_ids
            $this->updateExamCategory($exam, $plan, $questionIds);

            // Mark plan as attached
            $plan->markAsAttached();
        });

        if ($exam->meta['generated_questions_v2']) {
            return $exam->meta['generated_questions_v2'];
        }
        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     * @param array<int, string> $questionIds
     * @return array<int, array<string, mixed>>
     */
    protected function updateSectionStructure(array $sections, GenerationPlan $plan, array $questionIds): array
    {
        foreach ($sections as &$section) {
            if (($section['id'] ?? null) !== $plan->section_id) {
                continue;
            }

            $section['question_ids'] = $questionIds;

            $assembly = $section['assembly'] ?? [];

            switch ($plan->assembly_mode) {
                case 'inline':
                    $assembly = $this->attachInlinePlaceholders($assembly, $questionIds);
                    break;

                case 'blueprint':
                    $assembly = $this->attachBlueprintSlots($assembly, $plan->plan_data['slots'] ?? [], $questionIds);
                    break;

                case 'pool':
                    $assembly['question_ids'] = $questionIds;
                    break;
            }

            $section['assembly'] = $assembly;
        }

        return $sections;
    }

    /**
     * @param array<string, mixed> $assembly
     * @param array<int, string> $questionIds
     * @return array<string, mixed>
     */
    protected function attachInlinePlaceholders(array $assembly, array $questionIds): array
    {
        $placeholders = $assembly['placeholders'] ?? [];

        foreach ($placeholders as $index => &$placeholder) {
            $placeholder['question_id'] = $questionIds[$index] ?? null;
        }

        $assembly['placeholders'] = $placeholders;

        return $assembly;
    }

    /**
     * @param array<string, mixed> $assembly
     * @param array<int, array<string, mixed>> $slotsPlan
     * @param array<int, string> $questionIds
     * @return array<string, mixed>
     */
    protected function attachBlueprintSlots(array $assembly, array $slotsPlan, array $questionIds): array
    {
        $slots = $assembly['blueprint'] ?? [];
        $cursor = 0;

        foreach ($slots as $index => &$slot) {
            $planSlot = $slotsPlan[$index] ?? null;
            $pick = (int) ($planSlot['pick'] ?? $slot['pick'] ?? 0);

            if ($pick <= 0) {
                $slot['question_ids'] = [];
                continue;
            }

            $slot['question_ids'] = array_slice($questionIds, $cursor, $pick);
            $cursor += $pick;
        }

        $assembly['blueprint'] = $slots;

        return $assembly;
    }

    /**
     * @param array<int, string> $questionIds
     */
    protected function updateExamCategory(Exam $exam, GenerationPlan $plan, array $questionIds): void
    {
        /** @var ExamCategory|null $category */
        $category = $exam->categories()
            ->where('key', $plan->section_id)
            ->first();

        if (! $category) {
            return;
        }

        $meta = $category->meta ?? [];
        $meta['question_ids'] = $questionIds;
        $meta['assembly'] = $this->syncCategoryAssembly(
            $meta['assembly'] ?? [],
            $plan,
            $questionIds,
        );

        $category->meta = $meta;
        $category->save();
    }

    /**
     * @param array<string, mixed> $assembly
     * @param array<int, string> $questionIds
     * @return array<string, mixed>
     */
    protected function syncCategoryAssembly(array $assembly, GenerationPlan $plan, array $questionIds): array
    {
        switch ($plan->assembly_mode) {
            case 'inline':
                return $this->attachInlinePlaceholders($assembly, $questionIds);

            case 'blueprint':
                return $this->attachBlueprintSlots($assembly, $plan->plan_data['slots'] ?? [], $questionIds);

            case 'pool':
                $assembly['question_ids'] = $questionIds;
                return $assembly;

            default:
                return $assembly;
        }
    }
}

