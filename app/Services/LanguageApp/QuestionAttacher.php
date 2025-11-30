<?php

declare(strict_types=1);

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\GenerationPlan;
use App\Models\Question;
use App\Services\LanguageApp\Contracts\QuestionGroupContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QuestionAttacher
{
    public function __construct(
        private readonly QuestionAudioProcessor $audioProcessor,
        private readonly QuestionImageProcessor $imageProcessor
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $questions
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

            // ========== CREATE QUESTION RECORDS IN DATABASE ==========
            // Convert generated questions (array) to Question model records
            $questionRecords = [];

            // Get section info for unique question_id generation
            $category = ExamCategory::find($plan->section_id);
            $sectionKey = $category ? $category->key : "section_{$plan->section_id}";

            // Build group_id → database ID mapping for this section
            $groupIdMap = $this->buildQuestionGroupIdMap($exam, (int) $plan->section_id);

            foreach ($questions as $qIndex => $questionData) {
                try {
                    // ✅ VALIDATE CONTRACT: Ensure question meets contract requirements before attach
                    $rawQuestionId = $questionData['id'] ?? "q{$qIndex}";
                    $groupIdString = $questionData['group_id'] ?? null;

                    QuestionGroupContract::validateBeforeAttach($questionData, $groupIdString);

                    // Generate unique question_id
                    // For grouped questions: use group_id prefix (e.g., "listening-task-1_list-q1")
                    // For ungrouped: use section prefix (e.g., "sec-listening_q1")

                    // DEBUG: Log full questionData for pipeline troubleshooting
                    Log::info('[QuestionAttacher] Processing question - FULL DATA', [
                        'qIndex' => $qIndex,
                        'rawQuestionId' => $rawQuestionId,
                        'groupIdString' => $groupIdString,
                        'type' => $questionData['type'] ?? 'unknown',
                        'has_interaction' => ! empty($questionData['interaction']),
                        'interaction_keys' => is_array($questionData['interaction'] ?? null) ? array_keys($questionData['interaction']) : 'not_array',
                        'all_keys' => array_keys($questionData),
                    ]);

                    if ($groupIdString) {
                        // Grouped question: use group_id prefix to match skeleton questions
                        $uniqueQuestionId = QuestionIdGenerator::forGroupedQuestion($groupIdString, $rawQuestionId);
                    } else {
                        // Ungrouped question: use section prefix
                        $uniqueQuestionId = QuestionIdGenerator::forUngroupedQuestion($sectionKey, $rawQuestionId);
                    }

                    Log::debug('[QuestionAttacher] Generated uniqueQuestionId', [
                        'uniqueQuestionId' => $uniqueQuestionId,
                    ]);

                    // Resolve question_group_id from group_id string
                    $questionGroupId = $groupIdString ? ($groupIdMap[$groupIdString] ?? null) : null;

                    // CRITICAL: Question::insert() bypasses model casts, so we must manually
                    // json_encode() all JSON fields before inserting into database
                    $questionRecord = [
                        'exam_id' => $exam->id,
                        'section_id' => (int) $plan->section_id, // Cast to integer for database
                        'question_group_id' => $questionGroupId, // NEW: Link to QuestionGroup
                        'question_id' => $uniqueQuestionId,
                        'order' => $qIndex, // NEW: Order within section
                        'type' => $questionData['type'] ?? 'single_select',
                        'skills_measured' => json_encode($questionData['skills_measured'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'time_limit_sec' => $questionData['time_limit_sec'] ?? 0,
                        'instructions' => json_encode($questionData['instructions'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'stimulus' => json_encode($questionData['stimulus'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'interaction' => json_encode($questionData['interaction'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'response' => json_encode($questionData['response'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'scoring' => json_encode($questionData['scoring'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'metadata' => json_encode($questionData['metadata'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'constraints' => isset($questionData['constraints']) ? json_encode($questionData['constraints'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                        'randomization' => isset($questionData['randomization']) ? json_encode($questionData['randomization'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                        'outcome_reporting' => isset($questionData['outcome_reporting']) ? json_encode($questionData['outcome_reporting'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                        'io_signature' => isset($questionData['io_signature']) ? json_encode($questionData['io_signature'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                        'typical_errors' => isset($questionData['typical_errors']) ? json_encode($questionData['typical_errors'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                        'ui_hints' => isset($questionData['ui_hints']) ? json_encode($questionData['ui_hints'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                        'accessibility' => isset($questionData['accessibility']) ? json_encode($questionData['accessibility'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                        'status' => 'draft',
                    ];

                    $questionRecords[] = $questionRecord;
                } catch (\Throwable $e) {
                    Log::warning('[QuestionAttacher] Failed to prepare question record', [
                        'question_id' => $questionData['id'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Phase 2.3: INSERT-only (no skeleton UPDATE logic)
            // Skeleton questions no longer created in Research - synthesis creates Questions directly
            if (! empty($questionRecords)) {
                try {
                    // ✅ STEP 1: Check for duplicates WITHIN batch (internal validation)
                    $questionIds = array_column($questionRecords, 'question_id');
                    $uniqueIds = array_unique($questionIds);

                    if (count($questionIds) !== count($uniqueIds)) {
                        $duplicates = array_diff_assoc($questionIds, $uniqueIds);

                        Log::error('[QuestionAttacher] Duplicate question_id within batch', [
                            'exam_id' => $exam->id,
                            'section_id' => $plan->section_id,
                            'total_questions' => count($questionIds),
                            'unique_questions' => count($uniqueIds),
                            'duplicates' => array_values($duplicates),
                        ]);

                        throw new \Exception('Duplicate question_id within batch: '.implode(', ', $duplicates));
                    }

                    // ✅ P0.3 FIX (V6): Use insertOrIgnore to delegate deduplication to UNIQUE index
                    // Problem (V5): lockForUpdate() does NOT lock non-existent rows → race condition persists
                    // Solution: Let MySQL UNIQUE(question_id) be the single source of truth
                    //           - insertOrIgnore() gracefully handles duplicates without error
                    //           - P0.1 (dispatch lock) + P0.2 (execution key) already prevent 99.9% of duplicates
                    //           - insertOrIgnore() catches remaining 0.1% edge cases
                    // See: docs/fixes/p0-3-locking-strategy-problem.md
                    $inserted = 0;
                    DB::transaction(function () use ($questionRecords, $questionIds, $exam, $plan, &$inserted) {
                        // ✅ STEP 2: insertOrIgnore - no error on duplicate key constraint
                        // Returns number of ACTUALLY inserted rows (may be less than count($questionRecords))
                        $inserted = Question::insertOrIgnore($questionRecords);

                        // ✅ STEP 3: Log if some questions were skipped due to duplicates
                        if ($inserted < count($questionRecords)) {
                            // Find which question_ids already existed
                            $existingIds = Question::whereIn('question_id', $questionIds)
                                ->pluck('question_id')
                                ->toArray();

                            $duplicates = array_values(array_intersect($questionIds, $existingIds));

                            Log::warning('[QuestionAttacher] Some questions already exist (duplicates ignored)', [
                                'exam_id' => $exam->id,
                                'section_id' => $plan->section_id,
                                'expected' => count($questionRecords),
                                'inserted' => $inserted,
                                'duplicates_count' => count($duplicates),
                                'duplicate_ids' => $duplicates,
                            ]);
                        }

                        Log::info('[QuestionAttacher] Questions inserted successfully', [
                            'exam_id' => $exam->id,
                            'section_id' => $plan->section_id,
                            'prepared' => count($questionRecords),
                            'inserted' => $inserted,
                        ]);
                    });

                    // Log summary
                    Log::info('[QuestionAttacher] Question attach summary', [
                        'exam_id' => $exam->id,
                        'section_id' => $plan->section_id,
                        'total_records' => count($questionRecords),
                        'inserted' => $inserted,
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    // SQL constraint violation (duplicate key, FK violation, etc.)
                    Log::error('[QuestionAttacher] SQL constraint violation during INSERT', [
                        'exam_id' => $exam->id,
                        'section_id' => $plan->section_id,
                        'error_code' => $e->getCode(),
                        'error_message' => $e->getMessage(),
                        'question_ids' => array_column($questionRecords, 'question_id'),
                    ]);

                    throw $e;
                } catch (\Throwable $e) {
                    Log::error('[QuestionAttacher] Bulk insert/update failed', [
                        'exam_id' => $exam->id,
                        'section_id' => $plan->section_id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    throw $e; // Re-throw to rollback transaction
                }
            }

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

        // После создания вопросов - генерируем аудио где необходимо
        $this->generateAudioForQuestions($exam, $plan);

        // FIXED: Return only the questions that were attached in THIS call,
        // not all accumulated questions in exam.meta (over-generation bug)
        return $questions;
    }

    /**
     * Генерирует аудио для вопросов секции
     */
    protected function generateAudioForQuestions(Exam $exam, GenerationPlan $plan): void
    {
        // Пропускаем если TTS отключен
        if (! config('ai.tts.enabled', false)) {
            return;
        }

        try {
            // Получаем вопросы для этой секции
            $questions = Question::where('exam_id', $exam->id)
                ->where('section_id', $plan->section_id)
                ->whereNull('audio_file_path') // только те, у кого еще нет аудио
                ->get();

            if ($questions->isEmpty()) {
                return;
            }

            Log::info('[QuestionAttacher] Generating audio for questions', [
                'exam_id' => $exam->id,
                'section_id' => $plan->section_id,
                'count' => $questions->count(),
            ]);

            $result = $this->audioProcessor->processQuestions($questions->all());

            Log::info('[QuestionAttacher] Audio generation complete', [
                'exam_id' => $exam->id,
                'section_id' => $plan->section_id,
                'result' => $result,
            ]);

        } catch (\Exception $e) {
            // Не падаем если генерация аудио не удалась - это не критично
            Log::error('[QuestionAttacher] Failed to generate audio', [
                'exam_id' => $exam->id,
                'section_id' => $plan->section_id,
                'error' => $e->getMessage(),
            ]);
        }

        // Generate images for questions that need them
        try {
            $questions = Question::where('exam_id', $exam->id)
                ->where('section_id', $plan->section_id)
                ->whereNull('image_url') // только те, у кого еще нет изображений
                ->get();

            if ($questions->isEmpty()) {
                return;
            }

            Log::info('[QuestionAttacher] Generating images for questions', [
                'exam_id' => $exam->id,
                'section_id' => $plan->section_id,
                'count' => $questions->count(),
            ]);

            $result = $this->imageProcessor->processQuestions($questions->all());

            Log::info('[QuestionAttacher] Image generation complete', [
                'exam_id' => $exam->id,
                'section_id' => $plan->section_id,
                'result' => $result,
            ]);

        } catch (\Exception $e) {
            // Не падаем если генерация изображений не удалась - это не критично
            Log::error('[QuestionAttacher] Failed to generate images', [
                'exam_id' => $exam->id,
                'section_id' => $plan->section_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @param  array<int, string>  $questionIds
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
     * @param  array<string, mixed>  $assembly
     * @param  array<int, string>  $questionIds
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
     * @param  array<string, mixed>  $assembly
     * @param  array<int, array<string, mixed>>  $slotsPlan
     * @param  array<int, string>  $questionIds
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
     * @param  array<int, string>  $questionIds
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
     * @param  array<string, mixed>  $assembly
     * @param  array<int, string>  $questionIds
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

    /**
     * Build mapping from group_id string to QuestionGroup database ID
     *
     * @param  int  $sectionId  ExamCategory ID
     * @return array<string, int> Map of group_id → QuestionGroup.id
     */
    protected function buildQuestionGroupIdMap(Exam $exam, int $sectionId): array
    {
        $groups = \App\Models\QuestionGroup::where('exam_id', $exam->id)
            ->where('section_id', $sectionId)
            ->get(['id', 'group_id']);

        $map = [];
        foreach ($groups as $group) {
            $map[$group->group_id] = $group->id;
        }

        if (! empty($map)) {
            Log::debug('[QuestionAttacher] Built group_id map', [
                'exam_id' => $exam->id,
                'section_id' => $sectionId,
                'groups_count' => count($map),
            ]);
        }

        return $map;
    }
}
