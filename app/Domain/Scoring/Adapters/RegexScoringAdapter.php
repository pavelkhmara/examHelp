<?php

namespace App\Domain\Scoring\Adapters;

use App\Domain\Scoring\Contracts\ScoringAdapter;
use App\Domain\Scoring\Score;
use InvalidArgumentException;

final class RegexScoringAdapter implements ScoringAdapter
{
    public function validateConfig(array $scoring): void
    {
        // допускаем patterns[] либо один pattern
    }

    public function score(array $task, mixed $userAnswer): Score
    {
        $patterns = (array) ($task['patterns'] ?? $task['items'][0]['patterns'] ?? []);
        if (empty($patterns)) {
            $one = $task['pattern'] ?? $task['items'][0]['pattern'] ?? null;
            if ($one) {
                $patterns = [$one];
            }
        }
        if (empty($patterns)) {
            throw new InvalidArgumentException('REGEX scoring requires patterns.');
        }

        $text = (string) (is_array($userAnswer) ? ($userAnswer['text'] ?? '') : $userAnswer);
        $ok = false;
        foreach ($patterns as $re) {
            $delim = '#';
            $re = is_string($re) ? $re : '';
            if ($re === '') {
                continue;
            }
            if (@preg_match($delim.$re.$delim.'iu', $text)) {
                if (preg_match($delim.$re.$delim.'iu', $text)) {
                    $ok = true;
                    break;
                }
            }
        }

        return new Score(1, $ok ? 1 : 0, true);
    }
}
