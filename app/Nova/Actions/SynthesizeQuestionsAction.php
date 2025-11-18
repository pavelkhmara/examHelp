<?php

namespace App\Nova\Actions;

use App\Jobs\SynthesizeQuestionsJob;
use App\Models\GenerationPlan;
use App\Models\GenerationTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;

class SynthesizeQuestionsAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Run Full Pipeline (Generate/Attach)';

    public function handle(ActionFields $fields, Collection $models)
    {
        /** @var \App\Models\Exam $exam */
        foreach ($models as $exam) {
            // Validate that generation plans exist
            $plans = GenerationPlan::where('exam_id', $exam->id)->get();
            if ($plans->isEmpty()) {
                return Action::danger('No generation plans found. Run "Resolve Generation Plans" first.');
            }

            // Create generation task
            $task = GenerationTask::create([
                'exam_id' => $exam->id,
                'type' => 'synthesize_questions',
                'status' => 'queued',
                'request' => [
                    'plans_count' => $plans->count(),
                    'total_questions' => $plans->sum('total_questions'),
                ],
            ]);

            $task->addActivity('synthesize_queued', 'Operator requested question synthesis via Nova action', [
                'plans_count' => $plans->count(),
                'total_questions' => $plans->sum('total_questions'),
            ]);

            // Dispatch job
            SynthesizeQuestionsJob::dispatch($task->id);

            return Action::message("Question synthesis queued ({$plans->count()} sections, {$plans->sum('total_questions')} questions total). Check Activity Timeline for progress.");
        }

        return Action::message('Question synthesis queued.');
    }
}


