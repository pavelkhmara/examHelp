<?php

namespace App\Nova\Actions;

use App\Models\Exam;
use App\Models\GenerationTask;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class ConfirmIdentityAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Confirm/Reject Identity';

    /**
     * Perform the action on the given models.
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        foreach ($models as $exam) {
            /** @var Exam $exam */
            $task = GenerationTask::query()
                ->where('exam_id', $exam->id)
                ->whereIn('status', ['pending_confirmation', 'pending_clarification'])
                ->latest()
                ->first();

            if (! $task) {
                return Action::danger('No pending identity confirmation for this exam.');
            }

            $identity = $task->result['identity'] ?? null;
            if (! $identity) {
                return Action::danger('No identity data found for this exam.');
            }

            // Check if confirmation is needed:
            // 1. Old logic: hold = true (high confidence but requires user confirmation)
            // 2. New logic: confidence < 0.97 (low confidence, must confirm)
            $needsConfirmation = ($identity['hold'] ?? false) || ($identity['confidence'] ?? 0) < 0.97;

            if (! $needsConfirmation) {
                return Action::danger('This exam does not require identity confirmation (confidence >= 0.97 and no hold).');
            }

            $confirmed = $fields->get('confirmed');
            $notes = $fields->get('notes');

            if ($confirmed) {
                // User confirmed - remove hold and boost confidence
                $originalConfidence = $identity['confidence'] ?? 0;
                $identity['hold'] = false;
                $identity['user_confirmed'] = true;
                $identity['confirmed_at'] = now()->toISOString();

                // CRITICAL: User confirmation overrides low confidence
                // Set confidence to 1.0 (100%) since user manually verified
                if ($originalConfidence < 0.97) {
                    $identity['confidence'] = 1.0;
                    $identity['confidence_boosted_by'] = 'user_confirmation';
                    $identity['original_confidence'] = $originalConfidence;
                }

                if ($notes) {
                    $identity['confirmation_notes'] = $notes;
                }

                $result = (array) ($task->result ?? []);
                $result['identity'] = $identity;
                $task->result = $result;
                $task->status = 'queued'; // Reset to queued so job can pick it up
                $task->save();

                // Continue the pipeline
                \App\Jobs\RunExamResearchJob::dispatch($task->id)
                    ->delay(now()->addSeconds(1));

                return Action::message('Identity confirmed! Research pipeline will continue.');
            } else {
                // User rejected - re-run identity verification
                $identity['status'] = 'uncertain';
                $identity['confidence'] = 0.3;
                $identity['user_rejected'] = true;
                $identity['rejected_at'] = now()->toISOString();
                $identity['hold'] = true;
                $identity['user_provided_clarification'] = true; // Signal to job to re-run identity guard
                if ($notes) {
                    $identity['rejection_notes'] = $notes;
                    $identity['clarification_data'] = ['user_notes' => $notes];
                }

                $result = (array) ($task->result ?? []);
                $result['identity'] = $identity;
                $task->result = $result;
                $task->status = 'queued'; // Set to queued so job can pick it up
                $task->save();

                // Re-run the research job to verify identity again
                \App\Jobs\RunExamResearchJob::dispatch($task->id)
                    ->delay(now()->addSeconds(1));

                return Action::message('Identity rejected. Re-running identity verification with your notes.');
            }
        }

        return Action::message('Identity confirmation processed.');
    }

    /**
     * Get the fields available on the action.
     */
    public function fields(NovaRequest $request)
    {
        return [
            Boolean::make('Confirmed', 'confirmed')
                ->help('Check this if the identified exam is correct, leave unchecked to reject'),

            Textarea::make('Notes', 'notes')
                ->help('Optional notes about your decision')
                ->rows(3),
        ];
    }
}
