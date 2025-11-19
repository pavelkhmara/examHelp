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

            // IMPORTANT: Always prefer result['identity'] as it's the most current
            // verification_attempts stores historical data, but identity is updated with latest followups
            $identity = $result['identity'] ?? [];

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

        // Progress data
        $progressData = $this->buildProgressData($exam, $quickCheck, $confirmedIdentity, $latestTask);

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
            'progress' => $progressData,
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

    /**
     * Построить данные прогресса экзамена
     *
     * @param  Exam  $exam
     * @param  array  $quickCheck
     * @param  \App\Models\ConfirmedIdentity|null  $confirmedIdentity
     * @param  \App\Models\GenerationTask|null  $latestTask
     * @return array
     */
    protected function buildProgressData(
        Exam $exam,
        array $quickCheck,
        $confirmedIdentity,
        $latestTask
    ): array {
        // Определяем статус и метрики для каждого этапа
        $stages = [];

        // 1. Данные
        $dataStatus = $quickCheck['ready'] ? 'completed' : 'pending';
        $stages[] = [
            'id' => 'data',
            'label' => 'Данные',
            'status' => $dataStatus,
            'metrics' => [
                'completion' => $quickCheck['completion_percentage'] . '%',
            ],
            'duration' => null,
        ];

        // 2. Идентификация
        $identityStatus = 'pending';
        $identityMetrics = ['confirmed' => false];
        $identityDuration = null;

        if ($confirmedIdentity && $confirmedIdentity->is_valid) {
            $identityStatus = 'completed';
            $identityMetrics['confirmed'] = true;

            // Попытка вычислить duration из tasks
            $identityTask = $exam->generationTasks()
                ->where('type', 'research')
                ->where('status', 'completed')
                ->latest()
                ->first();

            if ($identityTask && $identityTask->created_at && $identityTask->updated_at) {
                $identityDuration = $identityTask->created_at->diffInSeconds($identityTask->updated_at);
            }
        } elseif ($latestTask && $latestTask->status === 'running') {
            // Проверяем, не идет ли сейчас Identity stage
            $activities = $latestTask->activities ?? [];
            foreach ($activities as $activity) {
                if (in_array($activity['event'] ?? '', [
                    'identity_guard_started',
                    'identity_verification_started',
                    'identity_low_confidence',
                ])) {
                    $identityStatus = 'in_progress';
                    break;
                }
            }
        }

        // Проверяем на ошибки в Identity
        $identityErrorTask = $exam->generationTasks()
            ->where('status', 'failed')
            ->where('type', 'research')
            ->latest()
            ->first();

        if ($identityErrorTask && $identityErrorTask->error) {
            // Проверяем, содержит ли ошибка слова identity/identification
            if (stripos($identityErrorTask->error, 'identity') !== false ||
                stripos($identityErrorTask->error, 'identification') !== false) {
                $identityStatus = 'error';
            }
        }

        $stages[] = [
            'id' => 'identity',
            'label' => 'Идентификация',
            'status' => $identityStatus,
            'metrics' => $identityMetrics,
            'duration' => $identityDuration,
        ];

        // 3. Структура
        $structureStatus = 'pending';
        $categoriesCount = $exam->categories_count ?? $exam->categories()->count();

        if ($categoriesCount > 0 || !empty($exam->meta['structure_v2'])) {
            $structureStatus = 'completed';
        } elseif ($latestTask && $latestTask->status === 'running') {
            // Проверяем, не идет ли сейчас Overview/Structure generation
            $activities = $latestTask->activities ?? [];
            foreach ($activities as $activity) {
                if (in_array($activity['event'] ?? '', [
                    'overview_generation_started',
                    'structure_building_started',
                    'categories_creation_started',
                ])) {
                    $structureStatus = 'in_progress';
                    break;
                }
            }

            // Также проверяем по research_status
            if (in_array($exam->research_status, ['running_overview', 'running_categories'])) {
                $structureStatus = 'in_progress';
            }
        }

        $stages[] = [
            'id' => 'structure',
            'label' => 'Структура',
            'status' => $structureStatus,
            'metrics' => [
                'categories' => $categoriesCount,
            ],
            'duration' => null,
        ];

        // 4. Примеры
        $examplesStatus = 'pending';
        $examplesCount = $exam->examples_count ?? $exam->examples()->count();

        if ($examplesCount > 0) {
            $examplesStatus = 'completed';
        } elseif ($latestTask && $latestTask->status === 'running') {
            if (in_array($exam->research_status, ['running_examples'])) {
                $examplesStatus = 'in_progress';
            }
        }

        $stages[] = [
            'id' => 'examples',
            'label' => 'Примеры',
            'status' => $examplesStatus,
            'metrics' => [
                'examples' => $examplesCount,
            ],
            'duration' => null,
        ];

        // 5. Вопросы
        $questionsStatus = 'pending';
        $questionsCount = $exam->questions()->count();

        if ($questionsCount > 0) {
            $questionsStatus = 'completed';
        } elseif ($latestTask && $latestTask->status === 'running') {
            // Проверяем по типу задачи
            if ($latestTask->type === 'generate_questions') {
                $questionsStatus = 'in_progress';
            }
        }

        $stages[] = [
            'id' => 'questions',
            'label' => 'Вопросы',
            'status' => $questionsStatus,
            'metrics' => [
                'questions' => $questionsCount,
            ],
            'duration' => null,
        ];

        // Вычисляем общий процент готовности
        $completedStages = array_filter($stages, fn ($s) => $s['status'] === 'completed');
        $overallPercentage = round((count($completedStages) / count($stages)) * 100);

        // Current task
        $currentTaskData = null;
        if ($latestTask && $latestTask->status === 'running') {
            $elapsedSeconds = $latestTask->created_at
                ? now()->diffInSeconds($latestTask->created_at)
                : 0;

            // Определяем stage и message
            $currentStage = 'unknown';
            $currentMessage = 'Обработка...';

            $activities = $latestTask->activities ?? [];
            $lastActivity = end($activities);

            if ($lastActivity) {
                $event = $lastActivity['event'] ?? '';

                // Map events to stages
                if (strpos($event, 'identity') !== false) {
                    $currentStage = 'identity';
                    $currentMessage = 'Проверка идентичности...';
                } elseif (strpos($event, 'overview') !== false || strpos($event, 'structure') !== false) {
                    $currentStage = 'overview';
                    $currentMessage = 'Генерация структуры...';
                } elseif (strpos($event, 'example') !== false) {
                    $currentStage = 'examples';
                    $currentMessage = 'Генерация примеров...';
                }
            }

            // Fallback to research_status
            if ($currentStage === 'unknown') {
                switch ($exam->research_status) {
                    case 'running_overview':
                        $currentStage = 'overview';
                        $currentMessage = 'Генерация структуры...';
                        break;
                    case 'running_examples':
                        $currentStage = 'examples';
                        $currentMessage = 'Генерация примеров...';
                        break;
                    default:
                        $currentMessage = 'Обработка задачи...';
                }
            }

            $currentTaskData = [
                'id' => $latestTask->id,
                'type' => $latestTask->type,
                'stage' => $currentStage,
                'elapsed_seconds' => $elapsedSeconds,
                'message' => $currentMessage,
            ];
        }

        // Latest error - show only if it's relevant (no successful task of same type after it)
        $latestErrorData = null;
        $failedTask = $exam->generationTasks()
            ->where('status', 'failed')
            ->latest()
            ->first();

        if ($failedTask && $failedTask->error) {
            // Check if there's a more recent successful task of the same type
            $hasSuccessfulTaskAfter = $exam->generationTasks()
                ->where('type', $failedTask->type)
                ->where('status', 'completed')
                ->where('id', '>', $failedTask->id)
                ->exists();

            // Only show error if no successful task of same type came after
            if (!$hasSuccessfulTaskAfter) {
                $errorStage = 'unknown';

                // Попытка определить stage из activities
                $activities = $failedTask->activities ?? [];
                foreach (array_reverse($activities) as $activity) {
                    $event = $activity['event'] ?? '';
                    if (strpos($event, 'identity') !== false) {
                        $errorStage = 'identity';
                        break;
                    } elseif (strpos($event, 'overview') !== false || strpos($event, 'structure') !== false) {
                        $errorStage = 'overview';
                        break;
                    }
                }

                // Укоротить сообщение об ошибке для компактного отображения
                $shortError = strlen($failedTask->error) > 100
                    ? substr($failedTask->error, 0, 100) . '...'
                    : $failedTask->error;

                $latestErrorData = [
                    'task_id' => $failedTask->id,
                    'type' => $failedTask->type,
                    'stage' => $errorStage,
                    'message' => $shortError,
                    'full_error' => $failedTask->error,
                    'occurred_at' => $failedTask->updated_at?->toIso8601String(),
                ];
            }
        }

        return [
            'overall_percentage' => $overallPercentage,
            'stages' => $stages,
            'current_task' => $currentTaskData,
            'latest_error' => $latestErrorData,
        ];
    }
}
