<?php

namespace App\Services\Nova;

use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\QuickCheckService;

/**
 * Card Manager - управление карточками (уведомлениями) для Nova UI
 *
 * Responsibilities:
 * - Определяет, какие карточки должны быть показаны для экзамена
 * - Приоритизирует карточки
 * - Управляет состоянием карточек (dismissed cards)
 *
 * Типы карточек:
 * - MissingFieldsCard: недостающие поля для Identity
 * - FieldsChangedCard: поля изменились, нужен перезапуск
 * - IdentityVariantsCard: несколько вариантов экзамена
 * - StalledTaskCard: задача зависла
 * - ThreeAttemptsFailedCard: все попытки исчерпаны
 */
class CardManager
{
    protected QuickCheckService $quickCheckService;

    public function __construct(QuickCheckService $quickCheckService)
    {
        $this->quickCheckService = $quickCheckService;
    }

    /**
     * Получить все активные карточки для экзамена
     *
     * @param  Exam  $exam
     * @return array
     * [
     *   ['type' => 'missing_fields', 'priority' => 1, 'data' => [...]],
     *   ['type' => 'fields_changed', 'priority' => 2, 'data' => [...]],
     * ]
     */
    public function getActiveCards(Exam $exam): array
    {
        $cards = [];

        // Проверяем dismissed cards
        $dismissedCards = $exam->meta['dismissed_cards'] ?? [];

        // 1. MissingFieldsCard - если поля не готовы и карточка не закрыта
        if (!in_array('missing_fields', $dismissedCards)) {
            $quickCheck = $this->quickCheckService->check($exam);
            if (!$quickCheck['ready']) {
                $cards[] = [
                    'type' => 'missing_fields',
                    'priority' => 1,
                    'data' => $quickCheck,
                ];
            }
        }

        // 2. FieldsChangedCard - если поля изменились после подтверждения
        if (!in_array('fields_changed', $dismissedCards)) {
            if ($this->hasFieldsChanged($exam)) {
                $cards[] = [
                    'type' => 'fields_changed',
                    'priority' => 2,
                    'data' => $this->getChangedFields($exam),
                ];
            }
        }

        // 3. StalledTaskCard - если есть зависшая задача
        if (!in_array('stalled_task', $dismissedCards)) {
            $stalledTask = $this->getStalledTask($exam);
            if ($stalledTask) {
                $cards[] = [
                    'type' => 'stalled_task',
                    'priority' => 3,
                    'data' => [
                        'task_id' => $stalledTask->id,
                        'type' => $stalledTask->type,
                        'stalled_since' => $stalledTask->heartbeat_at,
                    ],
                ];
            }
        }

        // Сортировать по приоритету
        usort($cards, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        return $cards;
    }

    /**
     * Закрыть (dismiss) карточку
     *
     * @param  Exam  $exam
     * @param  string  $cardType
     * @return void
     */
    public function dismissCard(Exam $exam, string $cardType): void
    {
        $dismissedCards = $exam->meta['dismissed_cards'] ?? [];
        if (!in_array($cardType, $dismissedCards)) {
            $dismissedCards[] = $cardType;
            $meta = $exam->meta ?? [];
            $meta['dismissed_cards'] = $dismissedCards;
            $exam->meta = $meta;
            $exam->save();
        }
    }

    /**
     * Сбросить все закрытые карточки
     *
     * @param  Exam  $exam
     * @return void
     */
    public function resetDismissedCards(Exam $exam): void
    {
        $meta = $exam->meta ?? [];
        $meta['dismissed_cards'] = [];
        $exam->meta = $meta;
        $exam->save();
    }

    /**
     * Проверить, изменились ли влияющие поля после подтверждения
     *
     * @param  Exam  $exam
     * @return bool
     */
    protected function hasFieldsChanged(Exam $exam): bool
    {
        $confirmedIdentity = $exam->confirmedIdentity()
            ->where('is_valid', true)
            ->first();

        if (!$confirmedIdentity) {
            return false;
        }

        // Проверяем, изменились ли поля
        $currentFields = [
            'title' => $exam->title,
            'user_input' => $exam->user_input,
            'level' => $exam->level,
            'description' => $exam->description,
        ];

        return $confirmedIdentity->hasSourceFieldsChanged($currentFields);
    }

    /**
     * Получить список изменившихся полей
     *
     * @param  Exam  $exam
     * @return array
     */
    protected function getChangedFields(Exam $exam): array
    {
        $confirmedIdentity = $exam->confirmedIdentity()
            ->where('is_valid', true)
            ->first();

        if (!$confirmedIdentity) {
            return [];
        }

        $sourceFields = $confirmedIdentity->source_fields ?? [];
        $currentFields = [
            'title' => $exam->title,
            'user_input' => $exam->user_input,
            'level' => $exam->level,
            'description' => $exam->description,
        ];

        $changedFields = [];
        foreach ($sourceFields as $field => $oldValue) {
            if (!isset($currentFields[$field]) || $currentFields[$field] !== $oldValue) {
                $changedFields[] = $field;
            }
        }

        return [
            'fields' => $changedFields,
            'affected_stages' => ['Identity', 'Overview'], // По умолчанию
        ];
    }

    /**
     * Получить зависшую задачу (если есть)
     *
     * @param  Exam  $exam
     * @return GenerationTask|null
     */
    protected function getStalledTask(Exam $exam): ?GenerationTask
    {
        // Задача считается зависшей, если:
        // - статус 'running'
        // - heartbeat_at старше 10 минут (или null)
        $stalledThreshold = now()->subMinutes(10);

        return $exam->generationTasks()
            ->where('status', 'running')
            ->where(function ($q) use ($stalledThreshold) {
                $q->whereNull('heartbeat_at')
                    ->orWhere('heartbeat_at', '<', $stalledThreshold);
            })
            ->first();
    }
}
