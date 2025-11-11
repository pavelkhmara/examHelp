<?php

namespace App\Nova\Cards;

use Laravel\Nova\Card;

/**
 * Followup Questions Card - показывается когда AI задаёт уточняющие вопросы
 *
 * Отображает:
 * - Список followup вопросов, на которые нужно ответить
 * - Форму для ответов (через ProvideAnswersAction)
 *
 * Показывается когда:
 * - Статус задачи: pending_clarification
 * - В результате есть followups.length > 0
 */
class FollowupQuestionsCard extends Card
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
            'cardType' => 'followup_questions',
            'followups' => $this->meta()['followups'] ?? [],
            'needFields' => $this->meta()['needFields'] ?? [],
            'taskId' => $this->meta()['taskId'] ?? null,
        ]);
    }
}
