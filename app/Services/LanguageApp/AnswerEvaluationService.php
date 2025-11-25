<?php

namespace App\Services\LanguageApp;

use App\Models\Question;

/**
 * Evaluates user answers against question scoring rules.
 *
 * Supports:
 * - MCQ types: single_select, multi_select, true_false, yes_no_ng
 * - Fill-in-blank: fill_blank, short_answer
 * - Open-ended: writing_prompt, speaking_prompt (placeholder for future LLM evaluation)
 */
class AnswerEvaluationService
{
    /**
     * Evaluate user answer for a question.
     *
     * @param  mixed  $userAnswer  User's answer (string, array, or object)
     * @return array{is_correct: ?bool, points_earned: float, points_possible: float, feedback: ?string, correct_answer: mixed}
     */
    public function evaluate(Question $question, mixed $userAnswer): array
    {
        $scoring = $question->scoring ?? [];
        $maxScore = $scoring['max_score'] ?? 1;
        $answerKey = $scoring['answer_key'] ?? null;
        $method = $scoring['method'] ?? 'exact';

        $result = match ($question->type) {
            'single_select', 'true_false', 'yes_no_ng' => $this->evaluateSingleSelect($userAnswer, $answerKey, $maxScore),
            'multi_select' => $this->evaluateMultiSelect($userAnswer, $answerKey, $scoring, $maxScore),
            'fill_blank', 'short_answer' => $this->evaluateFillBlank($userAnswer, $answerKey, $method, $maxScore),
            'matching' => $this->evaluateMatching($userAnswer, $answerKey, $maxScore),
            'ordering' => $this->evaluateOrdering($userAnswer, $answerKey, $maxScore),
            default => $this->evaluateOpenEnded($question, $userAnswer, $maxScore),
        };

        $result['correct_answer'] = $this->formatCorrectAnswer($question->type, $answerKey);

        return $result;
    }

    /**
     * Evaluate single-select type (one correct answer).
     */
    private function evaluateSingleSelect(mixed $userAnswer, mixed $answerKey, float $maxScore): array
    {
        $isCorrect = $this->normalizeAnswer($userAnswer) === $this->normalizeAnswer($answerKey);

        return [
            'is_correct' => $isCorrect,
            'points_earned' => $isCorrect ? $maxScore : 0,
            'points_possible' => $maxScore,
            'feedback' => $isCorrect ? 'Correct!' : 'Incorrect.',
        ];
    }

    /**
     * Evaluate multi-select type (multiple correct answers).
     */
    private function evaluateMultiSelect(mixed $userAnswer, mixed $answerKey, array $scoring, float $maxScore): array
    {
        $userAnswers = is_array($userAnswer) ? array_map([$this, 'normalizeAnswer'], $userAnswer) : [$this->normalizeAnswer($userAnswer)];
        $correctAnswers = is_array($answerKey) ? array_map([$this, 'normalizeAnswer'], $answerKey) : [$this->normalizeAnswer($answerKey)];

        sort($userAnswers);
        sort($correctAnswers);

        $exactMatch = $userAnswers === $correctAnswers;

        // Check for partial credit
        $partialRules = $scoring['partial_rules'] ?? [];
        $allowPartial = ! empty($partialRules) || ($scoring['partial_credit'] ?? false);

        if ($exactMatch) {
            return [
                'is_correct' => true,
                'points_earned' => $maxScore,
                'points_possible' => $maxScore,
                'feedback' => 'Correct!',
            ];
        }

        if ($allowPartial) {
            $correct = count(array_intersect($userAnswers, $correctAnswers));
            $incorrect = count(array_diff($userAnswers, $correctAnswers));
            $missed = count(array_diff($correctAnswers, $userAnswers));

            // Simple partial: (correct - incorrect) / total, min 0
            $score = max(0, ($correct - $incorrect) / count($correctAnswers)) * $maxScore;

            return [
                'is_correct' => false,
                'points_earned' => round($score, 2),
                'points_possible' => $maxScore,
                'feedback' => "Partially correct. {$correct} correct, {$incorrect} wrong, {$missed} missed.",
            ];
        }

        return [
            'is_correct' => false,
            'points_earned' => 0,
            'points_possible' => $maxScore,
            'feedback' => 'Incorrect.',
        ];
    }

    /**
     * Evaluate fill-in-blank type.
     */
    private function evaluateFillBlank(mixed $userAnswer, mixed $answerKey, string $method, float $maxScore): array
    {
        // Handle multiple blanks (object/array format)
        if (is_array($userAnswer) && is_array($answerKey)) {
            $correct = 0;
            $total = count($answerKey);

            foreach ($answerKey as $key => $expected) {
                $given = $userAnswer[$key] ?? '';
                if ($this->matchAnswer($given, $expected, $method)) {
                    $correct++;
                }
            }

            $score = ($correct / max($total, 1)) * $maxScore;
            $isCorrect = $correct === $total;

            return [
                'is_correct' => $isCorrect,
                'points_earned' => round($score, 2),
                'points_possible' => $maxScore,
                'feedback' => $isCorrect ? 'Correct!' : "{$correct}/{$total} blanks correct.",
            ];
        }

        // Single blank
        $isCorrect = $this->matchAnswer($userAnswer, $answerKey, $method);

        return [
            'is_correct' => $isCorrect,
            'points_earned' => $isCorrect ? $maxScore : 0,
            'points_possible' => $maxScore,
            'feedback' => $isCorrect ? 'Correct!' : 'Incorrect.',
        ];
    }

    /**
     * Evaluate matching type.
     */
    private function evaluateMatching(mixed $userAnswer, mixed $answerKey, float $maxScore): array
    {
        if (! is_array($userAnswer) || ! is_array($answerKey)) {
            return $this->incorrectResult($maxScore);
        }

        $correct = 0;
        $total = count($answerKey);

        foreach ($answerKey as $key => $expected) {
            $given = $userAnswer[$key] ?? null;
            if ($this->normalizeAnswer($given) === $this->normalizeAnswer($expected)) {
                $correct++;
            }
        }

        $score = ($correct / max($total, 1)) * $maxScore;
        $isCorrect = $correct === $total;

        return [
            'is_correct' => $isCorrect,
            'points_earned' => round($score, 2),
            'points_possible' => $maxScore,
            'feedback' => $isCorrect ? 'Correct!' : "{$correct}/{$total} matches correct.",
        ];
    }

    /**
     * Evaluate ordering type.
     */
    private function evaluateOrdering(mixed $userAnswer, mixed $answerKey, float $maxScore): array
    {
        if (! is_array($userAnswer) || ! is_array($answerKey)) {
            return $this->incorrectResult($maxScore);
        }

        $userNormalized = array_map([$this, 'normalizeAnswer'], $userAnswer);
        $correctNormalized = array_map([$this, 'normalizeAnswer'], $answerKey);

        $isCorrect = $userNormalized === $correctNormalized;

        return [
            'is_correct' => $isCorrect,
            'points_earned' => $isCorrect ? $maxScore : 0,
            'points_possible' => $maxScore,
            'feedback' => $isCorrect ? 'Correct order!' : 'Incorrect order.',
        ];
    }

    /**
     * Placeholder for open-ended evaluation (writing, speaking).
     * Future: integrate with LLM evaluation.
     */
    private function evaluateOpenEnded(Question $question, mixed $userAnswer, float $maxScore): array
    {
        // For now, return null for is_correct (requires manual/LLM evaluation)
        return [
            'is_correct' => null,
            'points_earned' => 0,
            'points_possible' => $maxScore,
            'feedback' => 'Answer recorded. Evaluation pending.',
        ];
    }

    /**
     * Match answer based on method.
     */
    private function matchAnswer(mixed $given, mixed $expected, string $method): bool
    {
        $given = $this->normalizeAnswer($given);

        if (is_array($expected)) {
            // Multiple acceptable answers
            foreach ($expected as $exp) {
                if ($this->matchSingle($given, $this->normalizeAnswer($exp), $method)) {
                    return true;
                }
            }

            return false;
        }

        return $this->matchSingle($given, $this->normalizeAnswer($expected), $method);
    }

    private function matchSingle(string $given, string $expected, string $method): bool
    {
        return match ($method) {
            'exact' => $given === $expected,
            'case_insensitive' => strtolower($given) === strtolower($expected),
            'contains' => str_contains(strtolower($given), strtolower($expected)),
            'regex' => (bool) preg_match("/{$expected}/i", $given),
            default => $given === $expected,
        };
    }

    /**
     * Normalize answer for comparison.
     */
    private function normalizeAnswer(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return trim(strtolower((string) $value));
    }

    /**
     * Format correct answer for response.
     */
    private function formatCorrectAnswer(string $type, mixed $answerKey): mixed
    {
        // Don't reveal answer for open-ended types
        if (in_array($type, ['writing_prompt', 'speaking_prompt'])) {
            return null;
        }

        return $answerKey;
    }

    private function incorrectResult(float $maxScore): array
    {
        return [
            'is_correct' => false,
            'points_earned' => 0,
            'points_possible' => $maxScore,
            'feedback' => 'Incorrect.',
        ];
    }
}
