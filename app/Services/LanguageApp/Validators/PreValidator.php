<?php

declare(strict_types=1);

namespace App\Services\LanguageApp\Validators;

use Illuminate\Support\Facades\Log;

/**
 * Pre-Validation Layer (Phase 1.6 - P1.2)
 *
 * Автоматически исправляет распространённые ошибки AI перед строгой валидацией.
 * Вместо немедленного FAIL даёт AI второй шанс через auto-fix.
 *
 * Auto-fixes:
 * - Missing 'id' → auto-generate "auto_q{index}"
 * - Invalid 'type' → fallback to 'single_select'
 * - Empty required arrays → default values
 *
 * Expected impact: 40-60% reduction in validation failures.
 */
class PreValidator
{
    /**
     * Valid question types (extensible)
     */
    private const VALID_TYPES = [
        'single_select',
        'multi_select',
        'true_false',
        'yes_no_ng',
        'essay',
        'short_answer',
        'matching',
        'ordering',
        'cloze',
        'listen_mcq',
        'dictation',
        'speaking_prompt',
        'roleplay',
        'writing_prompt',
        'translation',
        'fill_blank',
    ];

    /**
     * Default fallback type for invalid types
     */
    private const DEFAULT_TYPE = 'single_select';

    /**
     * Maximum tolerable auto-fixes per batch
     */
    private const MAX_TOLERABLE_FIXES = 3;

    /**
     * Pre-валидация и auto-fix распространённых AI ошибок
     *
     * @param  array  $questions  Массив вопросов от AI
     * @return array ['valid' => bool, 'fixed' => array, 'errors' => array, 'auto_fixes_applied' => int]
     */
    public function preValidate(array $questions): array
    {
        $fixed = [];
        $errors = [];

        foreach ($questions as $index => $question) {
            $questionErrors = [];

            // AUTO-FIX 1: Missing 'id'
            if (! isset($question['id']) || empty($question['id']) || ! is_string($question['id'])) {
                $oldId = $question['id'] ?? 'NULL';
                $question['id'] = 'auto_q'.($index + 1);
                $questionErrors[] = "Missing or invalid 'id' (was: {$oldId}), auto-generated: {$question['id']}";
            }

            // AUTO-FIX 2: Invalid 'type' → fallback to DEFAULT_TYPE
            if (! isset($question['type']) || ! in_array($question['type'], self::VALID_TYPES)) {
                $oldType = $question['type'] ?? 'NULL';
                $question['type'] = self::DEFAULT_TYPE;
                $questionErrors[] = "Invalid 'type' (was: '{$oldType}'), fallback to '{self::DEFAULT_TYPE}'";
            }

            // AUTO-FIX 3: Missing 'skills_measured' → default to empty array
            if (! isset($question['skills_measured']) || ! is_array($question['skills_measured'])) {
                $question['skills_measured'] = [];
                $questionErrors[] = "Missing 'skills_measured', set to empty array";
            }

            // AUTO-FIX 4: Missing 'metadata' → default to minimal structure
            if (! isset($question['metadata']) || ! is_array($question['metadata'])) {
                $question['metadata'] = [
                    'cefr' => ['B2'], // Default CEFR level
                    'difficulty' => 'medium',
                    'topic' => 'general',
                    'language' => 'en', // Will be overridden by specific exam language
                ];
                $questionErrors[] = "Missing 'metadata', set to default structure";
            }

            // AUTO-FIX 5: Missing 'time_limit_sec' → default to 60 seconds
            if (! isset($question['time_limit_sec']) || ! is_int($question['time_limit_sec'])) {
                $question['time_limit_sec'] = 60;
                $questionErrors[] = "Missing 'time_limit_sec', set to 60 seconds";
            }

            // AUTO-FIX 6: Missing 'version' → default to '2.0'
            if (! isset($question['version']) || empty($question['version'])) {
                $question['version'] = '2.0';
                $questionErrors[] = "Missing 'version', set to '2.0'";
            }

            // Log individual question errors
            if (! empty($questionErrors)) {
                $errors = array_merge($errors, $questionErrors);

                Log::warning('[PreValidator] Auto-fixes applied to question', [
                    'question_index' => $index,
                    'question_id' => $question['id'],
                    'fixes' => $questionErrors,
                ]);
            }

            $fixed[] = $question;
        }

        $autoFixCount = count($errors);
        $isValid = $this->errorsAreTolerable($errors);

        return [
            'valid' => $isValid,
            'fixed' => $fixed,
            'errors' => $errors,
            'auto_fixes_applied' => $autoFixCount,
        ];
    }

    /**
     * Проверяет, являются ли ошибки допустимыми (auto-fixable)
     *
     * @param  array  $errors  Список ошибок
     * @return bool True если ошибки можно исправить автоматически
     */
    private function errorsAreTolerable(array $errors): bool
    {
        // Если нет ошибок - всегда валидно
        if (empty($errors)) {
            return true;
        }

        // Допускаем до MAX_TOLERABLE_FIXES auto-fixes на batch
        // Если больше - вероятно AI сильно сломан, лучше FAIL
        return count($errors) <= self::MAX_TOLERABLE_FIXES;
    }
}
