<?php
declare(strict_types=1);

namespace App\Domain\Scoring\Adapters;

use App\Domain\Scoring\Contracts\ScoringAdapter;
use App\Domain\Scoring\Score;

/**
 * RubricScoringAdapter — суммирование по критериям рубрики (веса × баллы).
 * Помечаем auto_gradable=false — обычно требует проверки наставником.
 */
final class RubricScoringAdapter implements ScoringAdapter
{
    /**
     * @param array<string,mixed> $payload
     * @param mixed $userAnswer
     */
    public function score(array $payload, mixed $userAnswer): Score
    {
        $sum = 0.0;

        /** @var array<int,array<string,mixed>> $rubric */
        $rubric = (array)($payload['rubric'] ?? []);
        foreach ($rubric as $row) {
            $w = (float)($row['weight'] ?? 0.0);
            $score = (float)($row['score'] ?? 0.0);
            $sum += $w * $score;
        }

        return new Score(false, $sum, []);
    }
}
