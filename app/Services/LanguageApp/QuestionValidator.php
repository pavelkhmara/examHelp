<?php

declare(strict_types=1);

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\GenerationPlan;
use App\Services\LanguageApp\Validators\JsonSchemaQuestionV2;
use App\Support\LiteValidationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class QuestionValidator
{
    private const SELECTION_TYPES = [
        'single_select',
        'multi_select',
        'true_false',
        'yes_no_ng',
        'dropdown_cloze',
        'gap_cloze',
        'banked_cloze',
        'matching',
        'order_sentences',
        'order_words',
        'highlight_text',
        'listen_mcq',
        // ✅ NEW: Listening variants (2025-11-26)
        'listen_true_false',
        'listen_yes_no_ng',
    ];

    public function __construct(
        private readonly JsonSchemaQuestionV2 $schemaValidator,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $questions
     * @return array<int, array<string, mixed>>
     *
     * @throws LiteValidationException
     */
    public function validateAndFinalize(array $questions, GenerationPlan $plan, Exam $exam): array
    {
        if (empty($questions)) {
            return [];
        }

        $errors = [];
        $finalized = [];
        $existingIds = $this->collectExistingQuestionIds($exam);
        $timestamp = now()->toIso8601String();

        foreach ($questions as $index => $question) {
            if (! isset($question['id']) || ! is_string($question['id']) || $question['id'] === '') {
                $question['id'] = $this->makeProvisionalId($plan, $index);
            }

            // AUTO-FIX: Apply automatic fixes before validation
            $question = $this->autoFixQuestion($question, $exam);

            try {
                $validated = $this->schemaValidator->validate($question);
            } catch (\Throwable $e) {
                $errors["question_{$index}"][] = 'Schema validation failed: '.$e->getMessage();

                continue;
            }

            $logicErrors = $this->validateLogicalConsistency($validated);
            if (! empty($logicErrors)) {
                foreach ($logicErrors as $logicError) {
                    $errors["question_{$index}"][] = $logicError;
                }

                continue;
            }

            $questionId = $this->generateUniqueId($validated, $plan, $existingIds, $finalized);
            $existingIds[] = $questionId;

            $finalized[] = $this->enrichMetadata(
                question: $validated,
                questionId: $questionId,
                plan: $plan,
                generatedAt: $timestamp,
            );
        }

        // SOFT FAILURES: Log validation errors but return valid questions
        // Previously: threw exception and failed entire batch (strict mode)
        // Now: skip invalid questions, return valid ones (soft failures)
        if (! empty($errors)) {
            \Illuminate\Support\Facades\Log::warning('[QuestionValidator] Some questions failed validation, skipped', [
                'exam_id' => $exam->id,
                'plan_id' => $plan->id,
                'section_id' => $plan->section_id,
                'total_submitted' => count($questions),
                'valid_count' => count($finalized),
                'invalid_count' => count($errors),
                'errors' => $errors,
            ]);
        }

        return $finalized;
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array<int, string>
     */
    protected function validateLogicalConsistency(array $question): array
    {
        $errors = [];

        // Selection options vs answer_key
        if ($this->isSelectionType($question)) {
            $options = collect($question['interaction']['options'] ?? []);
            $optionIds = $options
                ->pluck('id')
                ->filter()
                ->values()
                ->all();

            $answerKey = $question['scoring']['answer_key'] ?? [];
            $answerIds = $this->extractAnswerKeyValues($answerKey);

            foreach ($answerIds as $answerId) {
                if ($answerId !== null && ! in_array($answerId, $optionIds, true)) {
                    $errors[] = "answer_key references non-existent option '{$answerId}'";
                }
            }
        }

        // Rubric validation
        $scoringMethod = $question['scoring']['method'] ?? null;
        if (in_array($scoringMethod, ['rubric', 'hybrid'], true)) {
            $criteria = $question['scoring']['rubric']['criteria'] ?? [];
            if (empty($criteria) || ! is_array($criteria)) {
                $errors[] = 'Rubric scoring requires rubric.criteria array.';
            } else {
                foreach ($criteria as $criterionIndex => $criterion) {
                    if (empty($criterion['name'])) {
                        $errors[] = "Rubric criterion #{$criterionIndex} is missing name.";
                    }
                    if (empty($criterion['levels']) || ! is_array($criterion['levels'])) {
                        $errors[] = "Rubric criterion #{$criterionIndex} must define levels.";
                    }
                }
            }
        }

        // Speaking prompt recording limit
        if (($question['type'] ?? null) === 'speaking_prompt') {
            $recordingLimit = Arr::get($question, 'response.recording_limit_sec');
            if (! is_int($recordingLimit) || $recordingLimit <= 0) {
                $errors[] = 'Speaking prompts require positive response.recording_limit_sec.';
            }
        }

        // Response type vs mode alignment
        $responseType = Arr::get($question, 'interaction.response_type');
        $responseMode = Arr::get($question, 'response.mode');
        if (! $this->isResponseAlignmentValid($responseType, $responseMode)) {
            $errors[] = "interaction.response_type '{$responseType}' does not align with response.mode '{$responseMode}'.";
        }

        // Time limit sanity
        $timeLimit = $question['time_limit_sec'] ?? null;
        if (! is_int($timeLimit) || $timeLimit <= 0) {
            $errors[] = 'time_limit_sec must be a positive integer.';
        } else {
            // Rule #2: Relaxed to 5-600 seconds (was 10-600)
            if ($this->isShortFormType($question) && ($timeLimit < 5 || $timeLimit > 600)) {
                $errors[] = "time_limit_sec={$timeLimit} is unreasonable for short-form question type.";
            }

            if ($this->isExtendedResponseType($question) && $timeLimit < 60) {
                $errors[] = "time_limit_sec={$timeLimit} is too low for extended response question.";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array<int, string>  $existingIds
     * @param  array<int, array<string, mixed>>  $finalized
     */
    protected function generateUniqueId(array $question, GenerationPlan $plan, array $existingIds, array $finalized): string
    {
        $candidate = $question['id'] ?? null;
        // Don't slug-ify question ID - AI returns valid IDs with dashes/underscores
        // Previously: Str::slug($candidate, '_') replaced dashes with underscores
        // This broke skeleton matching for IDs like "list-q1" → "list_q1"
        // $candidate = $candidate ? Str::slug($candidate, '_') : null;

        if ($candidate && ! in_array($candidate, $existingIds, true) && ! $this->idExistsInFinalized($candidate, $finalized)) {
            return $candidate;
        }

        $section = Str::slug($plan->section_id, '_');
        $type = Str::slug($question['type'] ?? 'question', '_');

        do {
            $random = Str::lower(Str::random(4));
            $candidate = sprintf(
                '%s_%s_%s_%s',
                $section ?: 'section',
                $type ?: 'type',
                now()->timestamp,
                $random,
            );
        } while (in_array($candidate, $existingIds, true) || $this->idExistsInFinalized($candidate, $finalized));

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>
     */
    protected function enrichMetadata(array $question, string $questionId, GenerationPlan $plan, string $generatedAt): array
    {
        $question['id'] = $questionId;
        $question['generated_at'] = $generatedAt;
        $question['generated_by_plan_id'] = $plan->id;
        $question['section_id'] = $plan->section_id;
        $question['assembly_mode'] = $plan->assembly_mode;

        $question['is_duplicate'] = $question['is_duplicate'] ?? false;
        $question['duplicate_of'] = $question['duplicate_of'] ?? null;
        $question['similarity_score'] = $question['similarity_score'] ?? 0.0;

        return $question;
    }

    /**
     * @return array<int, string>
     */
    protected function collectExistingQuestionIds(Exam $exam): array
    {
        $existing = $exam->meta['generated_questions_v2'] ?? [];

        $collection = collect($existing);

        return $collection
            ->pluck('id')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<int|string, mixed>  $answerKey
     * @return array<int, string|null>
     */
    protected function extractAnswerKeyValues(array $answerKey): array
    {
        $values = [];
        foreach ($answerKey as $value) {
            if (is_array($value)) {
                $values = array_merge($values, $this->extractAnswerKeyValues($value));
            } elseif (is_string($value) || is_int($value)) {
                $values[] = (string) $value;
            } else {
                $values[] = null;
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $question
     */
    protected function isSelectionType(array $question): bool
    {
        $type = $question['type'] ?? null;
        $responseMode = Arr::get($question, 'response.mode');
        $responseType = Arr::get($question, 'interaction.response_type');

        if (in_array($type, self::SELECTION_TYPES, true)) {
            return true;
        }

        return in_array($responseMode, ['selection', 'multi_selection'], true)
            || in_array($responseType, ['selection', 'multiple_selection'], true);
    }

    protected function isShortFormType(array $question): bool
    {
        $type = $question['type'] ?? '';

        return in_array($type, [
            'single_select',
            'multi_select',
            'true_false',
            'yes_no_ng',
            'short_answer',
            'numeric',
            'dictation',
        ], true);
    }

    protected function isExtendedResponseType(array $question): bool
    {
        $type = $question['type'] ?? '';

        return in_array($type, [
            'writing_prompt',
            'speaking_prompt',
            'roleplay',
            'translation',
        ], true);
    }

    protected function isResponseAlignmentValid(?string $responseType, ?string $responseMode): bool
    {
        if ($responseType === null || $responseMode === null) {
            return false;
        }

        $alignment = [
            'selection' => ['selection', 'multi_selection'],
            'multi_selection' => ['multi_selection', 'selection'],
            'text' => ['text', 'textarea', 'essay'],
            'free_text' => ['text', 'textarea', 'essay'],
            'audio' => ['audio'],
            'spoken' => ['audio'],
        ];

        if (! array_key_exists($responseType, $alignment)) {
            return true;
        }

        return in_array($responseMode, $alignment[$responseType], true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $finalized
     */
    protected function idExistsInFinalized(string $candidate, array $finalized): bool
    {
        foreach ($finalized as $item) {
            if (($item['id'] ?? null) === $candidate) {
                return true;
            }
        }

        return false;
    }

    protected function makeProvisionalId(GenerationPlan $plan, int $index): string
    {
        return sprintf(
            'tmp_%s_%d_%s',
            Str::slug($plan->section_id, '_'),
            $index,
            Str::lower(Str::random(6)),
        );
    }

    /**
     * AUTO-FIX: Apply automatic corrections to question before validation
     *
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>
     */
    protected function autoFixQuestion(array $question, Exam $exam): array
    {
        $fixes = [];

        // Schema Fix #1: Auto-fix missing instructions.brief
        [$question, $fix0] = $this->autoFixInstructionsBrief($question);
        if ($fix0) {
            $fixes[] = $fix0;
        }

        // Rule #1: Auto-fix invalid answer_key references
        [$question, $fix1] = $this->autoFixAnswerKey($question);
        if ($fix1) {
            $fixes[] = $fix1;
        }

        // Rules #2 & #3: Auto-fix time_limit_sec
        [$question, $fix2] = $this->autoFixTimeLimit($question);
        if ($fix2) {
            $fixes[] = $fix2;
        }

        // Rule #4: Auto-fix missing recording_limit_sec
        [$question, $fix4] = $this->autoFixRecordingLimit($question);
        if ($fix4) {
            $fixes[] = $fix4;
        }

        // Rule #5: Auto-fix response type alignment
        [$question, $fix5] = $this->autoFixResponseAlignment($question);
        if ($fix5) {
            $fixes[] = $fix5;
        }

        // Rule #6: Auto-fix minimal rubric
        [$question, $fix6] = $this->autoFixRubric($question);
        if ($fix6) {
            $fixes[] = $fix6;
        }

        // Log auto-fixes if any
        if (! empty($fixes)) {
            Log::info('[QuestionValidator] Auto-fixed question', [
                'exam_id' => $exam->id,
                'question_id' => $question['id'] ?? 'unknown',
                'question_type' => $question['type'] ?? 'unknown',
                'fixes' => $fixes,
            ]);
        }

        return $question;
    }

    /**
     * SCHEMA FIX #1: Auto-fix missing instructions.brief
     *
     * JSON Schema требует обязательное поле instructions.brief.
     * Если отсутствует - генерируем из instructions.text_html или типа вопроса.
     *
     * @param  array<string, mixed>  $question
     * @return array{array<string, mixed>, string|null}
     */
    protected function autoFixInstructionsBrief(array $question): array
    {
        $instructions = $question['instructions'] ?? [];

        // Если brief уже есть - ничего не делаем
        if (! empty($instructions['brief']) && is_string($instructions['brief'])) {
            return [$question, null];
        }

        // Генерируем brief из text_html (первые 100 символов без HTML тегов)
        if (! empty($instructions['text_html'])) {
            $textHtml = $instructions['text_html'];
            $plainText = strip_tags($textHtml);
            $brief = mb_substr($plainText, 0, 100);

            if (mb_strlen($plainText) > 100) {
                $brief .= '...';
            }

            $question['instructions']['brief'] = $brief;

            return [$question, 'instructions.brief: generated from text_html'];
        }

        // Fallback: генерируем из типа вопроса
        $type = $question['type'] ?? 'unknown';
        $defaultBrief = match ($type) {
            'single_select', 'multi_select' => 'Choose the correct answer(s)',
            'true_false' => 'Mark as True or False',
            'writing_prompt' => 'Write your answer',
            'speaking_prompt' => 'Record your answer',
            'matching' => 'Match the items',
            'dictation' => 'Listen and write',
            default => 'Complete the task',
        };

        $question['instructions']['brief'] = $defaultBrief;

        return [$question, "instructions.brief: generated default for type '{$type}'"];
    }

    /**
     * AUTO-FIX Rule #1: Fix invalid answer_key references
     *
     * Для selection типов собирает valid IDs из interaction.options[].
     * Для matching типа собирает IDs из interaction.matches[] (источники и цели).
     *
     * @param  array<string, mixed>  $question
     * @return array{array<string, mixed>, string|null}
     */
    protected function autoFixAnswerKey(array $question): array
    {
        if (! $this->isSelectionType($question)) {
            return [$question, null];
        }

        // Собираем valid IDs из interaction.options[]
        $optionsCollection = collect($question['interaction']['options'] ?? []);
        $validIds = $optionsCollection
            ->pluck('id')
            ->filter()
            ->values()
            ->all();

        // Для matching типа добавляем IDs из interaction.matches[]
        $type = $question['type'] ?? null;
        if ($type === 'matching' && isset($question['interaction']['matches'])) {
            $matches = $question['interaction']['matches'];

            // Собираем ID источников (stems) и целей (options/targets)
            foreach ($matches as $match) {
                if (isset($match['stem_id']) && is_string($match['stem_id'])) {
                    $validIds[] = $match['stem_id'];
                }
                if (isset($match['option_id']) && is_string($match['option_id'])) {
                    $validIds[] = $match['option_id'];
                }
                if (isset($match['target_id']) && is_string($match['target_id'])) {
                    $validIds[] = $match['target_id'];
                }
            }

            // Удаляем дубликаты
            $validIds = array_unique($validIds);
        }

        if (empty($validIds)) {
            return [$question, null];
        }

        $answerKey = $question['scoring']['answer_key'] ?? [];
        $answerIds = $this->extractAnswerKeyValues($answerKey);
        $fixed = false;
        $newAnswerKey = [];

        foreach ($answerIds as $answerId) {
            if ($answerId !== null && ! in_array($answerId, $validIds, true)) {
                // Invalid answer_key - replace with first valid ID
                $newAnswerKey[] = $validIds[0];
                $fixed = true;
            } else {
                $newAnswerKey[] = $answerId;
            }
        }

        if ($fixed) {
            $question['scoring']['answer_key'] = $newAnswerKey;

            return [$question, 'answer_key: remapped invalid IDs to first valid ID'];
        }

        return [$question, null];
    }

    /**
     * AUTO-FIX Rules #2 & #3: Fix time_limit_sec
     *
     * @param  array<string, mixed>  $question
     * @return array{array<string, mixed>, string|null}
     */
    protected function autoFixTimeLimit(array $question): array
    {
        $timeLimit = $question['time_limit_sec'] ?? null;

        if (! is_int($timeLimit) || $timeLimit <= 0) {
            return [$question, null];
        }

        $originalLimit = $timeLimit;

        // Rule #2: Short-form questions (5-600 seconds)
        if ($this->isShortFormType($question)) {
            if ($timeLimit < 5) {
                $question['time_limit_sec'] = 5;
            } elseif ($timeLimit > 600) {
                $question['time_limit_sec'] = 600;
            }
        }

        // Rule #3: Extended response questions (min 60 seconds)
        if ($this->isExtendedResponseType($question) && $timeLimit < 60) {
            // Set to 60 for speaking, 120 for writing
            $type = $question['type'] ?? '';
            if (in_array($type, ['writing_prompt', 'translation'], true)) {
                $question['time_limit_sec'] = 120;
            } else {
                $question['time_limit_sec'] = 60;
            }
        }

        if ($question['time_limit_sec'] !== $originalLimit) {
            return [$question, "time_limit_sec: adjusted from {$originalLimit}s to {$question['time_limit_sec']}s"];
        }

        return [$question, null];
    }

    /**
     * AUTO-FIX Rule #4: Fix missing recording_limit_sec for speaking prompts
     *
     * @param  array<string, mixed>  $question
     * @return array{array<string, mixed>, string|null}
     */
    protected function autoFixRecordingLimit(array $question): array
    {
        if (($question['type'] ?? null) !== 'speaking_prompt') {
            return [$question, null];
        }

        $recordingLimit = Arr::get($question, 'response.recording_limit_sec');

        if (! is_int($recordingLimit) || $recordingLimit <= 0) {
            // Set to time_limit_sec or 120 as fallback
            $timeLimit = $question['time_limit_sec'] ?? 120;
            $question['response'] = $question['response'] ?? [];
            $question['response']['recording_limit_sec'] = $timeLimit;

            return [$question, "response.recording_limit_sec: set to {$timeLimit}s (from time_limit_sec)"];
        }

        return [$question, null];
    }

    /**
     * AUTO-FIX Rule #5: Fix response type alignment
     *
     * @param  array<string, mixed>  $question
     * @return array{array<string, mixed>, string|null}
     */
    protected function autoFixResponseAlignment(array $question): array
    {
        $type = $question['type'] ?? null;

        if (! $type) {
            return [$question, null];
        }

        // Determine correct response_type and mode based on question type
        $correctValues = $this->getCorrectResponseValues($type);

        if (! $correctValues) {
            return [$question, null];
        }

        $fixed = [];

        // Fix interaction.response_type
        $currentResponseType = Arr::get($question, 'interaction.response_type');
        if ($currentResponseType !== $correctValues['response_type']) {
            $question['interaction'] = $question['interaction'] ?? [];
            $question['interaction']['response_type'] = $correctValues['response_type'];
            $fixed[] = "interaction.response_type: {$currentResponseType} → {$correctValues['response_type']}";
        }

        // Fix response.mode
        $currentMode = Arr::get($question, 'response.mode');
        if ($currentMode !== $correctValues['mode']) {
            $question['response'] = $question['response'] ?? [];
            $question['response']['mode'] = $correctValues['mode'];
            $fixed[] = "response.mode: {$currentMode} → {$correctValues['mode']}";
        }

        if (! empty($fixed)) {
            return [$question, implode(', ', $fixed)];
        }

        return [$question, null];
    }

    /**
     * Get correct response_type and mode for question type
     *
     * @return array{response_type: string, mode: string}|null
     */
    protected function getCorrectResponseValues(string $type): ?array
    {
        $selectionTypes = [
            'single_select',
            'multi_select',
            'true_false',
            'yes_no_ng',
            'dropdown_cloze',
            'banked_cloze',
            'matching',
            'listen_mcq',
            'highlight_text',
            'order_sentences',
            'order_words',
        ];

        $textTypes = [
            'short_answer',
            'essay',
            'writing_prompt',
            'translation',
        ];

        $audioTypes = [
            'speaking_prompt',
            'roleplay',
            'dictation',
        ];

        if (in_array($type, $selectionTypes, true)) {
            return ['response_type' => 'selection', 'mode' => 'selection'];
        }

        if (in_array($type, $textTypes, true)) {
            $mode = $type === 'short_answer' ? 'text' : 'textarea';

            return ['response_type' => 'text', 'mode' => $mode];
        }

        if (in_array($type, $audioTypes, true)) {
            return ['response_type' => 'audio', 'mode' => 'audio'];
        }

        return null;
    }

    /**
     * AUTO-FIX Rule #6: Create minimal rubric if missing
     *
     * @param  array<string, mixed>  $question
     * @return array{array<string, mixed>, string|null}
     */
    protected function autoFixRubric(array $question): array
    {
        $scoringMethod = $question['scoring']['method'] ?? null;

        if (! in_array($scoringMethod, ['rubric', 'hybrid'], true)) {
            return [$question, null];
        }

        $criteria = $question['scoring']['rubric']['criteria'] ?? [];

        if (empty($criteria) || ! is_array($criteria)) {
            // Create minimal rubric with one criterion
            $question['scoring']['rubric'] = [
                'criteria' => [
                    [
                        'name' => 'Quality',
                        'description' => 'Overall quality of response',
                        'levels' => [
                            ['score' => 0, 'description' => 'Poor'],
                            ['score' => 1, 'description' => 'Fair'],
                            ['score' => 2, 'description' => 'Good'],
                        ],
                    ],
                ],
            ];

            return [$question, 'rubric.criteria: created minimal rubric with Quality criterion'];
        }

        // Fix missing criterion names or levels
        $fixed = [];
        foreach ($criteria as $idx => &$criterion) {
            if (empty($criterion['name'])) {
                $criterion['name'] = 'Criterion '.($idx + 1);
                $fixed[] = "criterion[{$idx}].name";
            }

            if (empty($criterion['levels']) || ! is_array($criterion['levels'])) {
                $criterion['levels'] = [
                    ['score' => 0, 'description' => 'Poor'],
                    ['score' => 1, 'description' => 'Good'],
                ];
                $fixed[] = "criterion[{$idx}].levels";
            }
        }

        if (! empty($fixed)) {
            $question['scoring']['rubric']['criteria'] = $criteria;

            return [$question, 'rubric: fixed '.implode(', ', $fixed)];
        }

        return [$question, null];
    }
}
