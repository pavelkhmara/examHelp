<?php

namespace App\Domain\Scoring\Adapters;

use App\Domain\Scoring\Contracts\ScoringAdapter;
use App\Domain\Scoring\ScoringMode;
use App\Domain\Scoring\Support\Compare;

final class RegexScoringAdapter implements ScoringAdapter
{
    public function mode(): ScoringMode
    {
        return ScoringMode::REGEX;
    }

    public function validateConfig(array $scoring): void
    {
        if (empty($scoring['answer_key'])) {
            throw new \InvalidArgumentException('REGEX scoring requires answer_key / patterns.');
        }
    }

    public function score(array $answerKey, array $user, array $config = []): array
    {
        $per = []; $sum = 0; $max = 0;
        foreach ($answerKey as $qid => $correct) {
            $max += 1;
            $u = $user[$qid] ?? null;
            $ok = false;
            if (is_string($correct) && is_string($u)) {
                $ok = @preg_match($correct, $u) === 1;
            } elseif (is_array($correct)) {
                if (is_string($u)) {
                    // any-of patterns
                    $ok = false;
                    foreach ($correct as $pat) { if (@preg_match($pat, $u) === 1) { $ok = true; break; } }
                } elseif (is_array($u)) {
                    // assoc slots -> pattern
                    $all = true;
                    foreach ($correct as $k => $pat) {
                        $uv = $u[$k] ?? '';
                        if (@preg_match($pat, (string)$uv) !== 1) { $all = false; break; }
                    }
                    $ok = $all;
                }
            }
            $sc = $ok ? 1.0 : 0.0;
            $per[$qid] = ['score'=>$sc,'max'=>1.0];
            $sum += $sc;
        }
        return ['per_item'=>$per,'total'=>['score'=>$sum,'max'=>$max],'auto_gradable'=>true];
    }
}
