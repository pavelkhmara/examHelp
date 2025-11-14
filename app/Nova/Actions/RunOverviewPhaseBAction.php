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

class RunOverviewPhaseBAction extends Action implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public $name = 'Run Phase B (Assembly v2)';

    public function handle(ActionFields $fields, Collection $models)
    {
        /** @var \App\Models\Exam $exam */
        foreach ($models as $exam) {
            $skeleton = $exam->structure_v2 ?? null;
            if (! $skeleton) {
                return Action::danger('Phase A structure_v2 is required before Phase B.');
            }

            $task = GenerationTask::create([
                'exam_id' => $exam->id,
                'type' => 'research_phase_b',
                'status' => 'running',
                'request' => [],
            ]);

            try {
                $service = app(ExamResearchService::class);
                $result = $service->runPhaseB($exam, $task, $skeleton);

                $exam->structure_v2 = $result;
                $exam->research_status = 'completed';
                $exam->save();

                $task->update(['status' => 'completed', 'result' => $result]);
            } catch (\Throwable $e) {
                $task->update(['status' => 'failed', 'error' => $e->getMessage()]);
                return Action::danger('Phase B failed: '.$e->getMessage());
            }
        }

        return Action::message('Phase B completed. Assembly config saved.');
    }
}


