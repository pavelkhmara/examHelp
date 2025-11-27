<?php

namespace App\Nova\Actions;

use App\Models\GenerationTask;
use App\Support\Queue\TaskDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;

class ResetAndRestartResearch extends Action
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $name = '🔄 Reset & Restart Research';

    public $uriKey = 'reset-restart-research';

    public $standalone = false; // Not standalone - requires resource context

    public function fields(NovaRequest $request)
    {
        return [
            Boolean::make('Cancel Active Tasks', 'cancel_active_tasks')
                ->help('Cancel all queued/running/pending tasks for this exam')
                ->default(true),

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

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\Exam>  $models
     */
    public function handle(ActionFields $fields, $models): mixed
    {
        Log::info('🔵 [ResetAndRestartResearch] BUTTON CLICKED - Action started', [
            'timestamp' => now()->toDateTimeString(),
            'models_count' => $models->count(),
            'fields' => [
                'cancel_active_tasks' => $fields->cancel_active_tasks ?? null,
                'without_confirmation' => $fields->without_confirmation ?? null,
                'overview_model' => $fields->overview_model ?? null,
            ],
        ]);

        try {
            /** @var TaskDispatcher $tasks */
            $tasks = app(TaskDispatcher::class);

            $processedExams = [];
            $errors = [];

            /** @var \App\Models\Exam $exam */
            foreach ($models as $exam) {
                try {
                    // Step 1: Cancel active tasks if requested
                    $cancelledCount = 0;
                    /** @phpstan-ignore-next-line */
                    if ($fields->cancel_active_tasks) {
                        $cancelledCount = $this->cancelActiveTasks($exam);
                        Log::info('[ResetAndRestartResearch] Cancelled active tasks', [
                            'exam_id' => $exam->id,
                            'cancelled_count' => $cancelledCount,
                        ]);
                    }

                    // Step 2: Parse user_input from exam
                    $userInput = [];
                    if (! empty($exam->user_input)) {
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
                            'source' => 'reset_restart_auto_fill',
                        ];

                        Log::info('[ResetAndRestartResearch] Auto-filled user_input from exam data', [
                            'exam_id' => $exam->id,
                            'exam_title' => $exam->title,
                            'without_confirmation' => $fields->without_confirmation ?? true,
                        ]);
                    }

                    // Step 3: Get last uploaded document if exists
                    $documentId = null;
                    $lastDoc = $exam->documents()->latest()->first();
                    if ($lastDoc) {
                        $documentId = $lastDoc->id;
                    }

                    // Step 4: Build payload
                    $payload = [
                        'exam_id' => $exam->id,
                        'source' => 'nova_reset_restart',
                        'user_input' => $userInput,
                        'document_id' => $documentId,
                        'without_confirmation' => $fields->without_confirmation ?? true,
                        'overview_model' => $fields->overview_model ?? 'gpt-5-mini',
                        'force_restart' => true, // Flag to indicate this is a forced restart
                    ];

                    // Step 5: Enqueue new research task with UNIQUE timestamp-based key
                    // This ensures no idempotency blocking
                    $task = $tasks->enqueue(
                        type: 'research',
                        subject: $exam,
                        request: $payload,
                        jobClass: \App\Jobs\RunExamResearchJob::class,
                        idempotencyKey: "exam:{$exam->id}:research:force_restart:".now()->timestamp.':'.uniqid(),
                        queue: null
                    );

                    Log::info('[ResetAndRestartResearch] New research task created', [
                        'exam_id' => $exam->id,
                        'task_id' => $task->id,
                        'cancelled_count' => $cancelledCount,
                        'queue_driver' => config('queue.default'),
                    ]);

                    // Step 6: Ensure job is dispatched (redundant safety check)
                    // In case TaskDispatcher returned an existing task or dispatch failed
                    if ($task->status === 'queued' && $task->updated_at->eq($task->created_at)) {
                        Log::info('[ResetAndRestartResearch] Ensuring job dispatch (safety check)', [
                            'task_id' => $task->id,
                        ]);

                        try {
                            dispatch(new \App\Jobs\RunExamResearchJob($task->id));
                            Log::info('[ResetAndRestartResearch] Job dispatched successfully', [
                                'task_id' => $task->id,
                            ]);
                        } catch (\Throwable $e) {
                            Log::error('[ResetAndRestartResearch] Failed to dispatch job', [
                                'task_id' => $task->id,
                                'error' => $e->getMessage(),
                            ]);
                            throw $e; // Re-throw to be caught by outer catch
                        }
                    }

                    $processedExams[] = [
                        'exam_id' => $exam->id,
                        'exam_title' => $exam->title,
                        'task_id' => $task->id,
                        'cancelled_count' => $cancelledCount,
                    ];
                } catch (\Throwable $e) {
                    Log::error('[ResetAndRestartResearch] Failed to process exam', [
                        'exam_id' => $exam->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    $errors[] = "Exam {$exam->title}: {$e->getMessage()}";
                }
            }

            if (! empty($errors)) {
                return Action::danger('❌ Errors occurred: '.implode('; ', $errors));
            }

            $examCount = count($processedExams);
            $totalCancelled = array_sum(array_column($processedExams, 'cancelled_count'));

            return Action::message("✅ Processed {$examCount} exam(s). Cancelled {$totalCancelled} task(s) and started new research! 🔄 Refresh to see progress.");
        } catch (\Throwable $e) {
            Log::error('[ResetAndRestartResearch] Action failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Action::danger('❌ Action failed: '.$e->getMessage());
        }
    }

    /**
     * Cancel all active tasks for the exam
     *
     * @return int Number of cancelled tasks
     */
    private function cancelActiveTasks(\App\Models\Exam $exam): int
    {
        $activeTasks = GenerationTask::query()
            ->where('exam_id', $exam->id)
            ->whereIn('status', ['queued', 'running', 'pending_confirmation', 'pending_clarification'])
            ->get();

        $cancelledCount = 0;

        foreach ($activeTasks as $task) {
            $oldStatus = $task->status;

            // Mark task as cancelled
            $task->status = 'cancelled';
            $task->error = 'Manually cancelled via Reset & Restart Research action';

            // Add activity log (wrapped in try-catch in case activity table doesn't exist)
            try {
                $task->addActivity(
                    'task_cancelled',
                    "Task manually cancelled (was: {$oldStatus})",
                    ['old_status' => $oldStatus, 'reason' => 'reset_restart_action']
                );
            } catch (\Throwable $e) {
                Log::warning('[ResetAndRestartResearch] Failed to add activity log', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $task->save();

            $cancelledCount++;

            Log::info('[ResetAndRestartResearch] Task cancelled', [
                'task_id' => $task->id,
                'old_status' => $oldStatus,
                'type' => $task->type,
            ]);
        }

        return $cancelledCount;
    }

    /**
     * Get the displayable name of the action.
     */
    public function name(): string
    {
        return '🔄 Reset & Restart Research';
    }
}
