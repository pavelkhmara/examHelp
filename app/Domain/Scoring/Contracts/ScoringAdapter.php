<?php

namespace App\Domain\Scoring\Contracts;

use App\Domain\Scoring\Score;

/**
 * Унифицированный контракт скоринга: адаптер валидирует свой конфиг
 * и возвращает объект Score (а не массив/булевы значения).
 */
interface ScoringAdapter
{
    /**
     * Базовая проверка (и нормализация при необходимости) секции scoring.
     * Бросает ValidationException/InvalidArgumentException при проблемах.
     */
    public function validateConfig(array $scoring): void;

    /**
     * @param  array  $task  Нормализованный task-айтем (type+payload/items part)
     * @param  mixed  $userAnswer  Нормализованный ответ пользователя
     */
    public function score(array $task, mixed $userAnswer): Score;
}
