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
            // Check if ConfirmedIdentity exists and is valid
            $confirmedIdentity = \App\Models\ConfirmedIdentity::where('exam_id', $exam->id)
                ->where('is_valid', true)
                ->first();

            if (!$confirmedIdentity) {
                // No valid identity - run identification first
                // Create research task that will run identity then Phase A
                $task = GenerationTask::create([
                    'exam_id' => $exam->id,
                    'type' => 'research',
                    'status' => 'queued',
                    'request' => [
                        'source' => 'phase_a_auto_identity',
                        'use_two_phase_generation' => true,
                        'skip_examples' => true,
                        'stop_after_phase_a' => true, // Will stop after Phase A
                    ],
                ]);

                $task->addActivity('identity_required', 'Phase A requested but no ConfirmedIdentity - running identity first');

                \App\Jobs\RunExamResearchJob::dispatch($task->id);

                return Action::message('⚡ Identity not confirmed. Running Identity → Phase A automatically. Check Activity Timeline.');
            }

            // Identity exists - run Phase A only
            $task = GenerationTask::create([
                'exam_id' => $exam->id,
                'type' => 'research_phase_a',
                'status' => 'queued',
                'request' => [
                    'skip_examples' => true, // Examples generated separately via "3.5️⃣ Generate Examples"
                ],
            ]);

            $task->addActivity('phase_a_queued', 'Operator requested Phase A via Nova action');

            // Dispatch job
            \App\Jobs\RunPhaseAJob::dispatch($task->id);

            return Action::message('Phase A queued. Check Activity Timeline for progress.');
        }

        return Action::message('Phase A queued.');
    }
}


