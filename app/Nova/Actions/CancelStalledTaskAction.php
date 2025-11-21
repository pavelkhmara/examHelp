<?php

namespace App\Nova\Actions;

use App\Models\GenerationTask;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * CancelStalledTaskAction - Nova Action for cancelling stalled generation tasks
 *
 * Allows operators to manually cancel tasks that have stalled (no heartbeat > 10 min)
 * and optionally restart them.
 */
class CancelStalledTaskAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $uriKey = 'cancel-stalled-task';

    /**
     * Perform the action on the given models.
     *
     * @param  \Laravel\Nova\Fields\ActionFields  $fields
     * @param  \Illuminate\Support\Collection  $models
     * @return mixed
     */
    public function authorizedToRun(\Illuminate\Http\Request $request, $model)
    {
        return true;
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $action = $fields->get('action');

        foreach ($models as $exam) {
            // Find stalled task for this exam
            $stalledThreshold = now()->subMinutes(10);
            $stalledTask = $exam->generationTasks()
                ->where('status', 'running')
                ->where(function ($q) use ($stalledThreshold) {
                    $q->whereNull('heartbeat_at')
                        ->orWhere('heartbeat_at', '<', $stalledThreshold);
                })
                ->latest()
                ->first();

            if (!$stalledTask) {
                return Action::danger('No stalled task found for this exam.');
            }

            // Log the cancellation
            \Illuminate\Support\Facades\Log::warning('Manually cancelling stalled task', [
                'task_id' => $stalledTask->id,
                'exam_id' => $exam->id,
                'last_heartbeat' => $stalledTask->heartbeat_at?->toDateTimeString(),
                'action' => $action,
            ]);

            // Add activity log
            $activities = $stalledTask->activities ?? [];
            $activities[] = [
                'timestamp' => now()->toIso8601String(),
                'event' => 'manually_cancelled',
                'message' => "Task manually cancelled by operator: {$action}",
                'context' => [
                    'action' => $action,
                    'last_heartbeat' => $stalledTask->heartbeat_at?->toDateTimeString(),
                ],
            ];
            $stalledTask->activities = $activities;

            // Cancel the task
            $stalledTask->status = 'cancelled';
            $stalledTask->error = 'Stalled task cancelled by operator';
            $stalledTask->save();

            // Update exam status
            $exam->research_status = 'failed';
            $exam->save();

            // If action is to restart, dispatch a new research job
            if ($action === 'cancel_and_restart') {
                // Create a new task and dispatch
                $newTask = $exam->generationTasks()->create([
                    'type' => 'research',
                    'status' => 'queued',
                    'request' => $stalledTask->request ?? [],
                    'activities' => [
                        [
                            'timestamp' => now()->toIso8601String(),
                            'event' => 'restarted_after_stall',
                            'message' => 'Task restarted after stall cancellation',
                            'context' => [
                                'previous_task_id' => $stalledTask->id,
                            ],
                        ],
                    ],
                ]);

                \App\Jobs\RunExamResearchJob::dispatch($newTask->id);

                $exam->research_status = 'queued';
                $exam->save();

                return Action::message('Stalled task cancelled and restarted successfully.');
            }

            return Action::message('Stalled task cancelled successfully.');
        }
    }

    /**
     * Get the fields available on the action.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function fields(NovaRequest $request)
    {
        return [
            Select::make('Action')
                ->options([
                    'cancel_only' => 'Cancel Only',
                    'cancel_and_restart' => 'Cancel and Restart',
                ])
                ->default('cancel_and_restart')
                ->help('Choose whether to restart the research after cancelling'),
        ];
    }

    /**
     * Get the displayable name of the action.
     *
     * @return string
     */
    public function name()
    {
        return 'Cancel Stalled Task';
    }
}
