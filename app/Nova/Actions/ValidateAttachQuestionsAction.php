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

class ValidateAttachQuestionsAction extends Action implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public $name = '5️⃣ Validate & Attach';

    public $uriKey = 'validate-attach-questions';

    public function authorizedToRun(\Illuminate\Http\Request $request, $model): bool
    {
        return true;
    }

    /**
     * @param  Collection<int, \App\Models\Exam>  $models
     */
    public function handle(ActionFields $fields, Collection $models): mixed
    {
        /** @var \App\Models\Exam $exam */
        foreach ($models as $exam) {
            try {
                $plans = GenerationPlan::where('exam_id', $exam->id)->get();
                if ($plans->isEmpty()) {
                    return Action::danger('❌ No generation plans. Run "3️⃣ Resolve Plans" first.');
                }

                $orchestrator = app(GenerationOrchestrator::class);
                $totalAttached = 0;

                foreach ($plans as $plan) {
                    // Re-run only final steps by executing full pipeline (idempotent on attach)
                    $result = $orchestrator->runFullPipeline($plan);
                    $totalAttached += count($result);
                }

                return Action::message("Validation & Attachment completed. {$totalAttached} questions now attached.");
            } catch (\Throwable $e) {
                return Action::danger('Validate & Attach failed: '.$e->getMessage());
            }
        }

        return Action::message('No exams selected.');
    }
}
