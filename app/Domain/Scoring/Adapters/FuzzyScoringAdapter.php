<?php

namespace App\Domain\Scoring\Adapters;

use App\Domain\Scoring\Contracts\ScoringAdapter;
use App\Domain\Scoring\Score;

final class FuzzyScoringAdapter implements ScoringAdapter
{
    public function validateConfig(array $scoring): void
    {
        // threshold опционально (0..1)
    }

    public function score(array $task, mixed $userAnswer): Score
    {
        $expected = (string) (
            $task['answer'] ??
            $task['answer_key'] ??
            $task['items'][0]['answer'] ??
            $task['items'][0]['answer_key'] ??
            ''
        );
        $ua = (string) (is_array($userAnswer) ? ($userAnswer['text'] ?? '') : $userAnswer);

        $total = 1;
        $got = 0;

        if ($expected !== '' && $ua !== '') {
            $len = max(1, max(strlen($expected), strlen($ua)));
            $dist = levenshtein(mb_strtolower($expected), mb_strtolower($ua));
            $sim = 1 - ($dist / $len); // 0..1
            $threshold = (float) ($task['scoring']['threshold'] ?? 0.75);
            $got = $sim >= $threshold ? 1 : 0;
        }

        return new Score($total, $got, true);
    }
}
