<?php

namespace App\Nova\Cards;

use Laravel\Nova\Card;

/**
 * Fields Changed Card - показывается когда влияющие поля изменились после подтверждения
 *
 * Отображает:
 * - Список изменившихся полей
 * - Затронутые этапы (Identity, Overview)
 * - Предложение перезапустить Identity stage
 */
class FieldsChangedCard extends Card
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
     * Используем тот же компонент, что и IdentityClarifierCard
     *
     * @return string
     */
    public function component()
    {
        return 'identity-clarifier-card'; // Переиспользуем существующий компонент
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
            'cardType' => 'fields_changed',
            'changedFields' => $this->meta()['changedFields'] ?? [],
            'affectedStages' => $this->meta()['affectedStages'] ?? [],
        ]);
    }
}
