<?php

namespace App\Nova\Actions;

use App\Models\Exam;
use App\Models\GenerationTask;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class RejectAllVariantsAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Нет подходящих вариантов';

    public $uriKey = 'reject-all-variants';

    /**
     * Perform the action on the given models.
     */
    public function authorizedToRun(\Illuminate\Http\Request $request, $model)
    {
        return true;
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        foreach ($models as $exam) {
            /** @var Exam $exam */
            $task = GenerationTask::query()
                ->where('exam_id', $exam->id)
                ->whereIn('status', ['pending_confirmation', 'pending_clarification'])
                ->latest()
                ->first();

            if (!$task) {
                return Action::danger('No pending task found for this exam.');
            }

            $notes = $fields->get('notes');

            // Mark identity as rejected
            $identity = $task->result['identity'] ?? [];
            $identity['status'] = 'rejected';
            $identity['user_rejected_all'] = true;
            $identity['rejected_at'] = now()->toISOString();

            if (!empty($notes)) {
                $identity['rejection_notes'] = $notes;
            }

            $result = (array) ($task->result ?? []);
            $result['identity'] = $identity;
            $task->result = $result;
            $task->status = 'failed';
            $task->error = 'User rejected all exam variants - none match their exam. ' . ($notes ?? '');
            $task->save();

            // Add activity log
            $task->addActivity('user_rejected_all_variants', 'User rejected all exam variants', [
                'notes' => $notes ?? null,
            ]);

            // Update exam research status
            $exam->research_status = 'failed';
            $exam->save();

            return Action::message('All variants rejected. Research cancelled.');
        }

        return Action::message('Action completed.');
    }

    /**
     * Get the fields available on the action.
     */
    public function fields(NovaRequest $request)
    {
        return [
            Textarea::make('Notes', 'notes')
                ->placeholder('Optional: Explain why none of the variants match...')
                ->help('Please provide details about your exam so we can improve our research.')
                ->rows(3),
        ];
    }

    /**
     * Show only for exams with pending clarification tasks
     */
    public function authorizedToSee(\Illuminate\Http\Request $request)
    {
        // Only show on exam detail page
        if ($request->route('resource') === 'exams' && $request->route('resourceId')) {
            $exam = Exam::find($request->route('resourceId'));
            if ($exam) {
                $hasPendingTask = GenerationTask::query()
                    ->where('exam_id', $exam->id)
                    ->whereIn('status', ['pending_confirmation', 'pending_clarification'])
                    ->exists();

                return $hasPendingTask;
            }
        }

        return parent::authorizedToSee($request);
    }

    /**
     * Get the displayable name of the action.
     */
    public function name()
    {
        return '❌ Нет подходящих вариантов';
    }
}
