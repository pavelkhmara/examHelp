<?php

namespace App\Nova\Actions;

use App\Support\Queue\TaskDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;

class ResearchAction extends Action
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $name = 'Run Exam Research';

    public $standalone = false; // Not standalone - requires resource context

    public $showInline = true; // Show on resource detail page

    public $showOnIndex = true; // Show on index page

    public $showOnDetail = true; // Show on detail page

    public function fields(NovaRequest $request)
    {
        return [
            Boolean::make('Without Confirmation', 'without_confirmation')
                ->help('Skip identity confirmation step and auto-approve exam identity')
                ->default(true),

            Select::make('Overview Model', 'overview_model')
                ->options([
                    'gpt-5-mini' => 'GPT-5 Mini (faster, cheaper)',
                    'gpt-5' => 'GPT-5 (highest quality, slower)',
                ])
                ->default('gpt-5-mini')
                ->help('AI model for exam overview generation'),
        ];
    }

    public function handle(ActionFields $fields, $models)
    {
        \Illuminate\Support\Facades\Log::info('🔵 [ResearchAction] BUTTON CLICKED - Action started', [
            'timestamp' => now()->toDateTimeString(),
            'models_count' => $models->count(),
            'fields' => [
                'without_confirmation' => $fields->without_confirmation ?? null,
                'overview_model' => $fields->overview_model ?? null,
            ],
        ]);

        // For standalone actions on detail page, $models might be empty
        // Try to get exam from Nova Request
        if ($models->isEmpty()) {
            \Illuminate\Support\Facades\Log::info('🔵 [ResearchAction] Models collection is empty, trying to get from request');

            $request = app(\Laravel\Nova\Http\Requests\NovaRequest::class);
            $resourceId = $request->route('resourceId') ?? $request->get('resources');

            // If resourceId is an array, take first element
            if (is_array($resourceId)) {
                $resourceId = $resourceId[0] ?? null;
            }

            \Illuminate\Support\Facades\Log::info('🔵 [ResearchAction] Resource ID from request', [
                'resourceId' => $resourceId,
                'route_params' => $request->route()->parameters(),
            ]);

            if ($resourceId) {
                $exam = \App\Models\Exam::find($resourceId);
                if ($exam) {
                    $models = collect([$exam]);
                    \Illuminate\Support\Facades\Log::info('🔵 [ResearchAction] Found exam from request', [
                        'exam_id' => $exam->id,
                        'exam_title' => $exam->title,
                    ]);
                }
            }
        }

        // Final check
        if ($models->isEmpty()) {
            \Illuminate\Support\Facades\Log::warning('🔵 [ResearchAction] Still no exams after trying request');
            return Action::danger('❌ No exam selected. Please open an exam detail page and run this action from there.');
        }

        /** @var TaskDispatcher $tasks */
        $tasks = app(TaskDispatcher::class);

        $createdCount = 0;
        $existingCount = 0;
        $debugInfo = []; // Debug information to show in message

        /** @var \App\Models\Exam $exam */
        foreach ($models as $exam) {
            \Illuminate\Support\Facades\Log::info('🔵 [ResearchAction] Processing exam', [
                'exam_id' => $exam->id,
                'exam_title' => $exam->title,
                'research_status' => $exam->research_status,
            ]);

            // Validate exam readiness using QuickCheckService
            $quickCheckService = app(\App\Services\LanguageApp\QuickCheckService::class);
            $checkResult = $quickCheckService->check($exam);

            if (!$checkResult['ready']) {
                \Illuminate\Support\Facades\Log::warning('🔵 [ResearchAction] Exam not ready', [
                    'exam_id' => $exam->id,
                    'missing_critical' => $checkResult['missing_critical'],
                    'completion' => $checkResult['completion_percentage'],
                ]);

                // Update exam status to need_info
                $exam->update(['research_status' => 'need_info']);

                return Action::danger(
                    '⚠️ Cannot start research: Missing critical fields. ' .
                    $quickCheckService->getMissingFieldsMessage($checkResult) .
                    '. Please review the Quick Check panel on the exam detail page.'
                );
            }

            // Parse user_input from exam
            $userInput = [];
            if (!empty($exam->user_input)) {
                if (is_array($exam->user_input)) {
                    $userInput = $exam->user_input;
                } elseif (is_string($exam->user_input)) {
                    $decoded = json_decode($exam->user_input, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $userInput = $decoded;
                    }
                }
            }

            // Auto-fill user_input from exam data if empty and without_confirmation is true
            // This helps Identity Guard identify the exam even when user_input is not explicitly set
            if (empty($userInput) && ($fields->without_confirmation ?? true)) {
                $userInput = [
                    'exam_name' => $exam->title,
                    'slug' => $exam->slug,
                    'level' => $exam->level,
                    'description' => $exam->description,
                    'auto_filled' => true,
                    'source' => 'nova_action_auto_fill',
                ];

                \Illuminate\Support\Facades\Log::info('[ResearchAction] Auto-filled user_input from exam data', [
                    'exam_id' => $exam->id,
                    'exam_title' => $exam->title,
                    'without_confirmation' => $fields->without_confirmation ?? true,
                ]);
            }

            // Get last uploaded document if exists
            $documentId = null;
            $lastDoc = $exam->documents()->latest()->first();
            if ($lastDoc) {
                $documentId = $lastDoc->id;
            }

            // Build payload
            $payload = [
                'exam_id' => $exam->id,
                'source' => 'nova_action',
                'user_input' => $userInput,
                'document_id' => $documentId,
                'without_confirmation' => $fields->without_confirmation ?? true,
                'overview_model' => $fields->overview_model ?? 'gpt-5-mini',
            ];

            // Generate unique idempotency key for this request
            $requestIdempotencyKey = "exam:{$exam->id}:research:nova:" . time() . ':' . uniqid();

            // Enqueue research task
            \Illuminate\Support\Facades\Log::info('🔵 [ResearchAction] Before TaskDispatcher->enqueue()', [
                'exam_id' => $exam->id,
                'idempotency_key' => $requestIdempotencyKey,
                'payload_source' => $payload['source'] ?? 'unknown',
            ]);

            $task = $tasks->enqueue(
                type: 'research',
                subject: $exam,
                request: $payload,
                jobClass: \App\Jobs\RunExamResearchJob::class,
                idempotencyKey: $requestIdempotencyKey,
                queue: null
            );

            \Illuminate\Support\Facades\Log::info('🔵 [ResearchAction] After TaskDispatcher->enqueue()', [
                'task_id' => $task->id,
                'task_status' => $task->status,
                'task_idempotency_key' => $task->idempotency_key,
            ]);

            // Store debug info
            $debugInfo[] = sprintf(
                'Task #%d (type:%s, status:%s, created:%s)',
                $task->id,
                $task->type,
                $task->status,
                $task->created_at->format('H:i:s')
            );

            // Check if this is a newly created task or existing one
            // If the returned task's idempotency_key matches our request key, it's new
            // Otherwise, TaskDispatcher returned an existing task
            $isNew = ($task->idempotency_key === $requestIdempotencyKey);

            if ($isNew) {
                $createdCount++;
                \Illuminate\Support\Facades\Log::info('[ResearchAction] New task created', [
                    'exam_id' => $exam->id,
                    'task_id' => $task->id,
                    'task_status' => $task->status,
                ]);

                // ALWAYS dispatch job for new tasks (safety measure)
                try {
                    dispatch(new \App\Jobs\RunExamResearchJob($task->id));
                    \Illuminate\Support\Facades\Log::info('[ResearchAction] Job dispatched for new task', [
                        'task_id' => $task->id,
                    ]);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('[ResearchAction] Failed to dispatch job for new task', [
                        'task_id' => $task->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                $existingCount++;
                \Illuminate\Support\Facades\Log::info('[ResearchAction] Existing task returned (not creating new)', [
                    'exam_id' => $exam->id,
                    'task_id' => $task->id,
                    'task_status' => $task->status,
                    'task_created_at' => $task->created_at,
                    'task_age_seconds' => now()->diffInSeconds($task->created_at),
                    'requested_key' => $requestIdempotencyKey,
                    'returned_key' => $task->idempotency_key,
                ]);

                // IMPORTANT: If existing task is queued but old, re-dispatch the job
                // This handles cases where queue worker wasn't running or job failed to dispatch
                if ($task->status === 'queued' && $task->created_at->lt(now()->subMinutes(1))) {
                    \Illuminate\Support\Facades\Log::warning('[ResearchAction] Re-dispatching job for old queued task', [
                        'task_id' => $task->id,
                        'task_age' => $task->created_at->diffForHumans(),
                    ]);

                    try {
                        dispatch(new \App\Jobs\RunExamResearchJob($task->id));
                        \Illuminate\Support\Facades\Log::info('[ResearchAction] Job re-dispatched successfully', [
                            'task_id' => $task->id,
                        ]);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::error('[ResearchAction] Failed to re-dispatch job', [
                            'task_id' => $task->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        // Return appropriate message with debug info
        $debugSummary = implode(' | ', $debugInfo);

        if ($existingCount > 0 && $createdCount === 0) {
            // Get status of the existing task for better UX
            $existingTask = $task ?? null; // Last task from the loop
            $statusInfo = $existingTask ? " (status: {$existingTask->status})" : '';

            return Action::message("ℹ️ A research task is already running for this exam{$statusInfo}. Please wait for it to complete or use \"Reset & Restart Research\" to force a new run. DEBUG: {$debugSummary}");
        } elseif ($existingCount > 0) {
            return Action::message("✅ {$createdCount} new task(s) started! {$existingCount} exam(s) already have tasks running. DEBUG: {$debugSummary}");
        } else {
            return Action::message("✅ Research task started! DEBUG: {$debugSummary}. Check Nova -> Generation Tasks.");
        }
    }
}
