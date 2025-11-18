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
 * - FieldsChangedCard: поля изменились, нужен перезапуск
 * - StalledTaskCard: задача зависла
 * - CandidatesCard: несколько вариантов экзамена для выбора
 * - FollowupQuestionsCard: уточняющие вопросы или недостающие поля
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
     *   ['type' => 'fields_changed', 'priority' => 1, 'data' => [...]],
     *   ['type' => 'stalled_task', 'priority' => 2, 'data' => [...]],
     *   ['type' => 'candidates', 'priority' => 3, 'data' => [...]],
     *   ['type' => 'followup_questions', 'priority' => 4, 'data' => [...]],
     * ]
     */
    public function getActiveCards(Exam $exam): array
    {
        $cards = [];

        // Проверяем dismissed cards
        $dismissedCards = $exam->meta['dismissed_cards'] ?? [];

        // 1. FieldsChangedCard - если поля изменились после подтверждения
        if (!in_array('fields_changed', $dismissedCards)) {
            if ($this->hasFieldsChanged($exam)) {
                $cards[] = [
                    'type' => 'fields_changed',
                    'priority' => 1,
                    'data' => $this->getChangedFields($exam),
                ];
            }
        }

        // 2. StalledTaskCard - если есть зависшая задача
        if (!in_array('stalled_task', $dismissedCards)) {
            $stalledTask = $this->getStalledTask($exam);
            if ($stalledTask) {
                $cards[] = [
                    'type' => 'stalled_task',
                    'priority' => 2,
                    'data' => $this->getStalledTaskData($stalledTask),
                ];
            }
        }

        // 3. CandidatesCard - если есть несколько вариантов экзамена для выбора
        if (!in_array('candidates', $dismissedCards)) {
            $candidatesData = $this->getCandidatesData($exam);
            if ($candidatesData) {
                $cards[] = [
                    'type' => 'candidates',
                    'priority' => 3,
                    'data' => $candidatesData,
                ];
            }
        }

        // 4. FollowupQuestionsCard - если есть уточняющие вопросы
        if (!in_array('followup_questions', $dismissedCards)) {
            $followupsData = $this->getFollowupsData($exam);
            if ($followupsData) {
                $cards[] = [
                    'type' => 'followup_questions',
                    'priority' => 4,
                    'data' => $followupsData,
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

        // Build current fields including document_ids_hash
        $documentIds = $exam->documents()->pluck('id')->toArray();
        $currentFields = [
            'title' => $exam->title,
            'user_input' => $exam->user_input,
            'level' => $exam->level,
            'description' => $exam->description,
            'document_ids_hash' => md5(json_encode($documentIds)),
        ];

        // Technical fields that shouldn't be shown to user
        $technicalFields = ['document_ids_hash'];

        $changedFields = [];
        foreach ($sourceFields as $field => $oldValue) {
            // Skip technical fields in the display
            if (in_array($field, $technicalFields)) {
                continue;
            }

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
            ->latest()
            ->first();
    }

    /**
     * Get formatted data for stalled task card
     *
     * @param  GenerationTask  $task
     * @return array
     */
    protected function getStalledTaskData(GenerationTask $task): array
    {
        $lastHeartbeat = $task->heartbeat_at;
        $stalledSince = $lastHeartbeat
            ? $lastHeartbeat->diffForHumans()
            : 'No heartbeat recorded';

        return [
            'task_id' => $task->id,
            'type' => $task->type,
            'stalled_since' => $stalledSince,
            'last_heartbeat' => $lastHeartbeat?->toDateTimeString(),
        ];
    }

    /**
     * Получить данные для CandidatesCard
     *
     * Показывается когда:
     * - Есть task со статусом pending_confirmation
     * - В result есть identity.candidates.length > 0
     * - ИЛИ в логах (generation_logs) есть кандидаты
     *
     * @param  Exam  $exam
     * @return array|null
     */
    protected function getCandidatesData(Exam $exam): ?array
    {
        // Ищем последнюю задачу со статусом pending_confirmation
        $task = $exam->generationTasks()
            ->where('status', 'pending_confirmation')
            ->latest()
            ->first();

        if (!$task) {
            return null;
        }

        $candidates = [];

        // Сначала пробуем получить из result
        $result = $task->result ?? [];

        // IMPORTANT: Always prefer result['identity'] as it's the most current
        // verification_attempts stores historical data, but identity is updated with latest candidates
        $identity = $result['identity'] ?? [];
        $candidates = $identity['candidates'] ?? [];

        // Если в result нет кандидатов, ищем в логах
        if (empty($candidates)) {
            // Ищем последний лог со стейджем 'identity'
            $identityLog = $task->logs()
                ->where('stage', 'identity')
                ->latest()
                ->first();

            if ($identityLog && isset($identityLog->response['candidates'])) {
                $candidates = $identityLog->response['candidates'];
            }
        }

        // Показываем карточку только если есть кандидаты
        if (empty($candidates) || count($candidates) === 0) {
            return null;
        }

        return [
            'task_id' => $task->id,
            'candidates' => $candidates,
        ];
    }

    /**
     * Получить данные для FollowupQuestionsCard
     *
     * Показывается когда:
     * - Есть task со статусом pending_clarification
     * - В result есть identity.followups.length > 0 ИЛИ identity.need_fields.length > 0
     * - ИЛИ в логах (generation_logs) есть followups/need_fields
     *
     * @param  Exam  $exam
     * @return array|null
     */
    protected function getFollowupsData(Exam $exam): ?array
    {
        // Ищем последнюю задачу со статусом pending_clarification
        $task = $exam->generationTasks()
            ->where('status', 'pending_clarification')
            ->latest()
            ->first();

        if (!$task) {
            return null;
        }

        // Сначала пробуем получить из result
        $result = $task->result ?? [];

        // IMPORTANT: Always prefer result['identity'] as it's the most current
        // verification_attempts stores historical data, but identity is updated with latest followups
        $identity = $result['identity'] ?? [];
        $followups = $identity['followups'] ?? [];
        $needFields = $identity['need_fields'] ?? [];

        // Если в result нет данных, ищем в логах
        if (empty($followups) && empty($needFields)) {
            // Ищем последний лог со стейджем 'identity'
            $identityLog = $task->logs()
                ->where('stage', 'identity')
                ->latest()
                ->first();

            if ($identityLog) {
                $followups = $identityLog->response['followups'] ?? [];
                $needFields = $identityLog->response['need_fields'] ?? [];
            }
        }

        // Показываем карточку только если есть followup вопросы или need_fields
        if (empty($followups) && empty($needFields)) {
            return null;
        }

        return [
            'task_id' => $task->id,
            'followups' => $followups,
            'need_fields' => $needFields,
        ];
    }
}
