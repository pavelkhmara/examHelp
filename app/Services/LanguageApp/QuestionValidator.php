<?php

declare(strict_types=1);

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\GenerationPlan;
use App\Services\LanguageApp\Validators\JsonSchemaQuestionV2;
use App\Support\LiteValidationException;
use Illuminate\Support\Arr;
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
        'video_mcq',
    ];

    public function __construct(
        private readonly JsonSchemaQuestionV2 $schemaValidator,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $questions
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

        if (! empty($errors)) {
            throw new LiteValidationException($errors, 'Generated questions failed logical validation.');
        }

        return $finalized;
    }

    /**
     * @param array<string, mixed> $question
     * @return array<int, string>
     */
    protected function validateLogicalConsistency(array $question): array
    {
        $errors = [];

        // Selection options vs answer_key
        if ($this->isSelectionType($question)) {
            $optionIds = collect($question['interaction']['options'] ?? [])
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
            if ($this->isShortFormType($question) && ($timeLimit < 10 || $timeLimit > 600)) {
                $errors[] = "time_limit_sec={$timeLimit} is unreasonable for short-form question type.";
            }

            if ($this->isExtendedResponseType($question) && $timeLimit < 60) {
                $errors[] = "time_limit_sec={$timeLimit} is too low for extended response question.";
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $question
     * @param array<int, string> $existingIds
     * @param array<int, array<string, mixed>> $finalized
     */
    protected function generateUniqueId(array $question, GenerationPlan $plan, array $existingIds, array $finalized): string
    {
        $candidate = $question['id'] ?? null;
        $candidate = $candidate ? Str::slug($candidate, '_') : null;

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
     * @param array<string, mixed> $question
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

        return collect($existing)
            ->pluck('id')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->values()
            ->all();
    }

    /**
     * @param array<int|string, mixed> $answerKey
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
     * @param array<string, mixed> $question
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
     * @param array<int, array<string, mixed>> $finalized
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
}

