<?php

namespace App\Nova\Actions;

use App\Models\GenerationPlan;
use App\Services\LanguageApp\GenerationOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;

class SynthesizeQuestionsAction extends Action implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public $name = 'Run Full Pipeline (Generate/Attach)';

    public function handle(ActionFields $fields, Collection $models)
    {
        /** @var \App\Models\Exam $exam */
        foreach ($models as $exam) {
            try {
                $plans = GenerationPlan::where('exam_id', $exam->id)->get();
                if ($plans->isEmpty()) {
                    return Action::danger('No generation plans found. Run "Resolve Generation Plans" first.');
                }

                $orchestrator = app(GenerationOrchestrator::class);
                $totalAttached = 0;

                foreach ($plans as $plan) {
                    $result = $orchestrator->runFullPipeline($plan);
                    $totalAttached += count($result);
                }

                return Action::message("Pipeline completed. Attached {$totalAttached} questions.");
            } catch (\Throwable $e) {
                return Action::danger('Pipeline failed: '.$e->getMessage());
            }
        }

        return Action::message('No exams selected.');
    }
}


