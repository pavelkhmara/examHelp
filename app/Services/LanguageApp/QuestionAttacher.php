<?php

declare(strict_types=1);

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\GenerationPlan;
use App\Models\Question;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QuestionAttacher
{
    public function __construct(
        private readonly QuestionAudioProcessor $audioProcessor
    ) {}
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

            // ========== CREATE QUESTION RECORDS IN DATABASE ==========
            // Convert generated questions (array) to Question model records
            $questionRecords = [];
            foreach ($questions as $questionData) {
                try {
                    // CRITICAL: Question::insert() bypasses model casts, so we must manually
                    // json_encode() all JSON fields before inserting into database
                    $questionRecord = [
                        'exam_id' => $exam->id,
                        'section_id' => (int) $plan->section_id, // Cast to integer for database
                        'question_id' => $questionData['id'] ?? 'q_' . uniqid(),
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

            // Bulk insert questions (much faster than individual creates)
            // Use insertOrIgnore to handle retry scenarios (idempotent insert)
            if (!empty($questionRecords)) {
                $inserted = Question::insertOrIgnore($questionRecords);
                Log::info('[QuestionAttacher] Created question records in database', [
                    'exam_id' => $exam->id,
                    'section_id' => $plan->section_id,
                    'count' => count($questionRecords),
                    'inserted' => $inserted,
                ]);
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

        if ($exam->meta['generated_questions_v2']) {
            return $exam->meta['generated_questions_v2'];
        }
        return [];
    }

    /**
     * Генерирует аудио для вопросов секции
     */
    protected function generateAudioForQuestions(Exam $exam, GenerationPlan $plan): void
    {
        // Пропускаем если TTS отключен
        if (!config('ai.tts.enabled', false)) {
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

