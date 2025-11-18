<?php

namespace App\Nova\Cards;

use Laravel\Nova\Card;

/**
 * ExamWorkflowHub - умная карточка-хаб для экзамена
 *
 * Отображает разные режимы в зависимости от состояния экзамена:
 * - missing_fields: если QuickCheck не ready (критичные поля отсутствуют)
 * - pending_confirmation: если есть pending_confirmation task (кандидаты для выбора)
 * - pending_clarification: если есть pending_clarification task (вопросы/поля для заполнения)
 * - fields_changed: если ConfirmedIdentity invalid (поля изменились после подтверждения)
 * - stalled: если задача зависла (heartbeat > 10 min)
 * - status: default (обычный статус экзамена)
 *
 * Приоритет режимов: pending_* > missing > fields_changed > stalled > status
 */
class ExamWorkflowHub extends Card
{
    /**
     * The width of the card (1/3, 1/2, 2/3, full).
     *
     * @var string
     */
    public $width = 'full';

    /**
     * ExamWorkflowHub constructor.
     *
     * @param  string|null  $examId
     */
    public function __construct(?string $examId = null)
    {
        parent::__construct();

        if ($examId) {
            $this->withMeta(['examId' => $examId]);
        }
    }

    /**
     * Get the component name for the element.
     *
     * @return string
     */
    public function component()
    {
        return 'exam-workflow-hub';
    }
}
