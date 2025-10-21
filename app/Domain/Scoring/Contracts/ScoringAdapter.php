<?php
declare(strict_types=1);

namespace App\Domain\Scoring\Contracts;

use App\Domain\Scoring\Score;

/**
 * Базовый контракт адаптера скоринга.
 */
interface ScoringAdapter
{
    /**
     * @param array<string,mixed> $payload  Канонический payload задания (ожидаемые ответы и т.п.)
     * @param mixed $userAnswer             Ответ пользователя в формате, ожидаемом адаптером
     */
    public function score(array $payload, mixed $userAnswer): Score;
}
