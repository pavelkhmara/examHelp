<?php

namespace App\Nova\Actions;

use App\Models\GenerationTask;
use App\Services\LanguageApp\ExamResearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;

class RunOverviewPhaseAAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = '1️⃣ Phase A: Skeleton';

    public $uriKey = 'run-phase-a';

    /**
     * Determine if the user is authorized to run the action.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    public function authorizedToRun(\Illuminate\Http\Request $request, $model)
    {
        return true;
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        /** @var \App\Models\Exam $exam */
        foreach ($models as $exam) {
            $task = GenerationTask::create([
                'exam_id' => $exam->id,
                'type' => 'research_phase_a',
                'status' => 'queued',
                'request' => [],
            ]);

            $task->addActivity('phase_a_queued', 'Operator requested Phase A via Nova action');

            // Dispatch job
            \App\Jobs\RunPhaseAJob::dispatch($task->id);

            return Action::message('Phase A queued. Check Activity Timeline for progress.');
        }

        return Action::message('Phase A queued.');
    }
}


