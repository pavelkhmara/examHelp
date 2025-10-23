<?php

namespace App\Domain\Scoring\Adapters;

use App\Domain\Scoring\Contracts\ScoringAdapter;
use App\Domain\Scoring\Score;

/**
 * Для multi_select, matching, order_* и пр. — частичное начисление.
 * Простое правило: +1 за каждое верное совпадение, -1 за каждый лишний выбор.
 * Минимум 0, максимум = count(correct).
 */
final class PartialScoringAdapter implements ScoringAdapter
{
    public function validateConfig(array $scoring): void
    {
        // по умолчанию — без специф. полей
    }

    public function score(array $task, mixed $userAnswer): Score
    {
        // multi_select
        $opts = (array) ($task['options'] ?? $task['items'][0]['options'] ?? []);
        $correct = array_values(array_map(
            fn ($o) => (string) $o['id'],
            array_filter($opts, fn ($o) => ! empty($o['is_correct']))
        ));
        $total = max(1, count($correct));

        $selected = array_map('strval', (array) ($userAnswer['selected_option_ids'] ?? []));
        $hits = count(array_intersect($correct, $selected));
        $wrong = count(array_diff($selected, $correct));

        $got = max(0, $hits - $wrong);

        return new Score($total, $got, true);
    }
}
