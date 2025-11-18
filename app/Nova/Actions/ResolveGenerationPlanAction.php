<?php

namespace App\Nova\Actions;

use App\Jobs\ResolveGenerationPlansJob;
use App\Models\GenerationTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;

class ResolveGenerationPlanAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Resolve Generation Plans (v2)';

    public function handle(ActionFields $fields, Collection $models)
    {
        /** @var \App\Models\Exam $exam */
        foreach ($models as $exam) {
            // Validate that structure_v2 exists (from Phase A)
            $structure = $exam->structure_v2 ?? null;
            if (!$structure) {
                return Action::danger('Phase A structure_v2 is required before resolving plans.');
            }

            // Create generation task
            $task = GenerationTask::create([
                'exam_id' => $exam->id,
                'type' => 'resolve_plans',
                'status' => 'queued',
                'request' => [],
            ]);

            $task->addActivity('resolve_plans_queued', 'Operator requested plan resolution via Nova action');

            // Dispatch job
            ResolveGenerationPlansJob::dispatch($task->id);

            return Action::message('Plan resolution queued. Check Activity Timeline for progress.');
        }

        return Action::message('Plan resolution queued.');
    }
}


