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
            if (! $identity || ! ($identity['hold'] ?? false)) {
                return Action::danger('No identity hold to confirm for this exam.');
            }

            $confirmed = $fields->get('confirmed');
            $notes = $fields->get('notes');

            if ($confirmed) {
                // User confirmed - remove hold and continue pipeline
                $identity['hold'] = false;
                $identity['user_confirmed'] = true;
                $identity['confirmed_at'] = now()->toISOString();
                if ($notes) {
                    $identity['confirmation_notes'] = $notes;
                }

                $result = (array) ($task->result ?? []);
                $result['identity'] = $identity;
                $task->result = $result;
                $task->save();

                // Continue the pipeline
                \App\Jobs\RunExamResearchJob::dispatch($task->id)
                    ->delay(now()->addSeconds(1));

                return Action::message('Identity confirmed! Research pipeline will continue.');
            } else {
                // User rejected - mark uncertain and keep hold
                $identity['status'] = 'uncertain';
                $identity['confidence'] = 0.3;
                $identity['user_rejected'] = true;
                $identity['rejected_at'] = now()->toISOString();
                $identity['hold'] = true;
                if ($notes) {
                    $identity['rejection_notes'] = $notes;
                }

                $result = (array) ($task->result ?? []);
                $result['identity'] = $identity;
                $task->result = $result;
                $task->status = 'pending';
                $task->save();

                return Action::message('Identity rejected. Please run research again with corrected input.');
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
