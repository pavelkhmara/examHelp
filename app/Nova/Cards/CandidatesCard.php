<?php

namespace App\Nova\Cards;

use Laravel\Nova\Card;

/**
 * Candidates Card - показывается когда AI нашёл несколько вариантов экзамена
 *
 * Отображает:
 * - Список кандидатов (candidates) для выбора
 * - Возможность выбрать правильный вариант экзамена
 *
 * Показывается когда:
 * - Статус задачи: pending_confirmation
 * - В результате есть candidates.length > 0
 */
class CandidatesCard extends Card
{
    /**
     * The width of the card (1/3, 1/2, full).
     *
     * @var string
     */
    public $width = 'full';

    /**
     * Get the component name for the card.
     *
     * Используем тот же компонент, что и IdentityClarifierCard,
     * но передаём cardType для правильного отображения
     *
     * @return string
     */
    public function component()
    {
        return 'identity-clarifier-card';
    }

    /**
     * Prepare the card for JSON serialization.
     *
     * @return array<string, array>
     */
    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'examId' => $this->meta()['examId'] ?? null,
            'cardType' => 'candidates',
            'candidates' => $this->meta()['candidates'] ?? [],
            'taskId' => $this->meta()['taskId'] ?? null,
        ]);
    }
}
