<?php

namespace App\Nova\Cards;

use Laravel\Nova\Card;

/**
 * Missing Fields Card - показывается когда не хватает полей для запуска Identity
 *
 * Отображает:
 * - Список недостающих critical и recommended полей
 * - Процент готовности
 * - Inline-формы для заполнения (через IdentityClarifierCard паттерн)
 */
class MissingFieldsCard extends Card
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
     * так как функциональность похожа (показать информацию + действия)
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
            'cardType' => 'missing_fields',
            'checkResult' => $this->meta()['checkResult'] ?? null,
        ]);
    }
}
