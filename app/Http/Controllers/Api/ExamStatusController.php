<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Services\LanguageApp\QuickCheckService;
use Illuminate\Http\JsonResponse;

/**
 * ExamStatusController - эндпоинт для получения статуса экзамена
 *
 * Используется Vue-компонентом ExamWorkflowHub для polling статуса
 * и определения viewMode (missing_fields, pending_confirmation, status, etc.)
 */
class ExamStatusController extends Controller
{
    protected QuickCheckService $quickCheckService;

    public function __construct(QuickCheckService $quickCheckService)
    {
        $this->quickCheckService = $quickCheckService;
    }

    /**
     * Получить полный статус экзамена
     *
     * @param  string  $examId
     * @return JsonResponse
     */
    public function show(string $examId): JsonResponse
    {
        /** @var Exam $exam */
        $exam = Exam::query()
            ->with(['generationTasks' => function ($q) {
                $q->latest()->limit(10);
            }, 'confirmedIdentity'])
            ->findOrFail($examId);

        // Latest task
        $latestTask = $exam->generationTasks->first();

        // Pending task (pending_confirmation, pending_clarification, waiting_for_confirmation)
        $pendingTask = $exam->generationTasks
            ->first(fn ($task) => in_array($task->status, [
                'pending_confirmation',
                'pending_clarification',
                'waiting_for_confirmation',
            ]));

        // Quick Check (missing fields)
        $quickCheck = $this->quickCheckService->check($exam);

        // Confirmed Identity status
        $confirmedIdentity = $exam->confirmedIdentity()
            ->where('is_valid', true)
            ->first();

        $confirmedIdentityStatus = null;
        if ($confirmedIdentity) {
            // Build current fields including document_ids_hash
            $documentIds = $exam->documents()->pluck('id')->toArray();
            $currentFields = [
                'title' => $exam->title,
                'user_input' => $exam->user_input,
                'level' => $exam->level,
                'description' => $exam->description,
                'document_ids_hash' => md5(json_encode($documentIds)),
            ];

            $hasFieldsChanged = $confirmedIdentity->hasSourceFieldsChanged($currentFields);

            if ($hasFieldsChanged) {
                $confirmedIdentityStatus = [
                    'is_valid' => false,
                    'has_fields_changed' => true,
                    'changed_fields' => $this->getChangedFieldsList($confirmedIdentity, $currentFields),
                ];
            } else {
                $confirmedIdentityStatus = [
                    'is_valid' => true,
                    'has_fields_changed' => false,
                ];
            }
        }

        // Stalled task
        $stalledTask = $this->getStalledTask($exam);
        $stalledTaskData = null;
        if ($stalledTask) {
            $stalledTaskData = [
                'id' => $stalledTask->id,
                'type' => $stalledTask->type,
                'stalled_since' => $stalledTask->heartbeat_at
                    ? $stalledTask->heartbeat_at->diffForHumans()
                    : 'No heartbeat recorded',
                'last_heartbeat' => $stalledTask->heartbeat_at?->toDateTimeString(),
            ];
        }

        // Pending task details (candidates, followups, need_fields)
        $pendingTaskData = null;
        if ($pendingTask) {
            $result = $pendingTask->result ?? [];

            // Extract identity data from new structure (verification_attempts) or fallback to old
            if (isset($result['verification_attempts']) && !empty($result['verification_attempts'])) {
                $latestAttempt = end($result['verification_attempts']);
                $identity = $latestAttempt['identity_result'] ?? [];
            } else {
                $identity = $result['identity'] ?? [];
            }

            $pendingTaskData = [
                'id' => $pendingTask->id,
                'type' => $pendingTask->type,
                'status' => $pendingTask->status,
                'created_at' => $pendingTask->created_at?->toIso8601String(),
                'candidates' => $identity['candidates'] ?? [],
                'followups' => $identity['followups'] ?? [],
                'need_fields' => $identity['need_fields'] ?? [],
                'confidence' => $identity['confidence'] ?? 0,
            ];
        }

        return response()->json([
            'exam_id' => $exam->id,
            'research_status' => $exam->research_status,
            'latest_task' => $latestTask ? [
                'id' => $latestTask->id,
                'type' => $latestTask->type,
                'status' => $latestTask->status,
                'created_at' => $latestTask->created_at?->toIso8601String(),
            ] : null,
            'pending_task' => $pendingTaskData,
            'quick_check' => $quickCheck,
            'confirmed_identity' => $confirmedIdentityStatus,
            'stalled_task' => $stalledTaskData,
        ]);
    }

    /**
     * Получить список изменившихся полей
     *
     * @param  \App\Models\ConfirmedIdentity  $confirmedIdentity
     * @param  array  $currentFields
     * @return array
     */
    protected function getChangedFieldsList($confirmedIdentity, array $currentFields): array
    {
        $sourceFields = $confirmedIdentity->source_fields ?? [];
        $changedFields = [];

        // Technical fields that shouldn't be shown to user
        $technicalFields = ['document_ids_hash'];

        foreach ($sourceFields as $field => $oldValue) {
            // Skip technical fields in the display
            if (in_array($field, $technicalFields)) {
                continue;
            }

            if (!isset($currentFields[$field]) || $currentFields[$field] !== $oldValue) {
                $changedFields[] = $field;
            }
        }

        return $changedFields;
    }

    /**
     * Получить зависшую задачу (если есть)
     *
     * @param  Exam  $exam
     * @return \App\Models\GenerationTask|null
     */
    protected function getStalledTask(Exam $exam): ?\App\Models\GenerationTask
    {
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
}
