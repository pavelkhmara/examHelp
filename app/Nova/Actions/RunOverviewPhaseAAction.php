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

class RunOverviewPhaseAAction extends Action implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public $name = 'Run Phase A (Skeleton v2)';

    public function handle(ActionFields $fields, Collection $models)
    {
        /** @var \App\Models\Exam $exam */
        foreach ($models as $exam) {
            $task = GenerationTask::create([
                'exam_id' => $exam->id,
                'type' => 'research_phase_a',
                'status' => 'running',
                'request' => [],
            ]);

            try {
                $service = app(ExamResearchService::class);
                $result = $service->runPhaseA($exam, $task);

                $exam->structure_v2 = $result;
                $exam->research_status = 'completed';
                $exam->save();

                $task->update(['status' => 'completed', 'result' => $result]);
            } catch (\Throwable $e) {
                $task->update(['status' => 'failed', 'error' => $e->getMessage()]);
                return Action::danger('Phase A failed: '.$e->getMessage());
            }
        }

        return Action::message('Phase A completed. Structure V2 saved.');
    }
}


