<?php

namespace App\Domain\Scoring\Adapters;

use App\Domain\Scoring\Contracts\ScoringAdapter;
use App\Domain\Scoring\Score;

final class RubricScoringAdapter implements ScoringAdapter
{
    public function validateConfig(array $scoring): void
    {
        // rubric — автооценка не выполняется, проверяем только, что рубрика существует (опционально)
    }

    public function score(array $task, mixed $userAnswer): Score
    {
        // Ручная проверка ⇒ авто-оценка недоступна
        $total = (int) ($task['scoring']['max_points'] ?? 1);

        return new Score($total, 0, false);
    }
}
