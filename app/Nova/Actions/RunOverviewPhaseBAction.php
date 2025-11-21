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

class RunOverviewPhaseBAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = '2️⃣ Phase B: Assembly';

    public $uriKey = 'run-phase-b';

    /**
     * Determine if the action should be available for the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    public function authorizedToSee(\Illuminate\Http\Request $request)
    {
        // Nova вызывает authorizedToSee БЕЗ resourceId для первичной проверки
        // Возвращаем true, чтобы action был виден в UI
        // Реальная валидация происходит в handle()
        if (! $request->resourceId) {
            return true; // CHANGED: было false - блокировало показ action в Nova UI
        }

        // Если есть resourceId - проверяем, что экзамен существует
        $exam = \App\Models\Exam::find($request->resourceId);
        return (bool) $exam;
    }

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
            // Check research_status for Phase A completion
            $status = $exam->research_status;
            $validStatuses = ['phase_a_completed', 'phase_b_completed', 'running_phase_b', 'completed'];
            if (!in_array($status, $validStatuses)) {
                return Action::danger("❌ Phase A not completed (status: {$status}). Run \"1️⃣ Phase A: Skeleton\" first.");
            }

            $skeleton = $exam->structure_v2 ?? null;
            if (! $skeleton) {
                return Action::danger('❌ Phase A not completed. Run "1️⃣ Phase A: Skeleton" first.');
            }

            // Check if sections exist
            $sections = $skeleton['sections'] ?? [];
            if (empty($sections)) {
                return Action::danger('❌ No sections found in structure_v2. Re-run Phase A.');
            }

            // Check if any section is missing assembly
            $allSectionsHaveAssembly = true;
            foreach ($sections as $section) {
                if (empty($section['assembly'])) {
                    $allSectionsHaveAssembly = false;
                    break;
                }
            }

            if ($allSectionsHaveAssembly) {
                return Action::danger('✅ Phase B already completed. Run "3️⃣ Resolve Plans" next.');
            }

            $task = GenerationTask::create([
                'exam_id' => $exam->id,
                'type' => 'research_phase_b',
                'status' => 'queued',
                'request' => [],
            ]);

            $task->addActivity('phase_b_queued', 'Operator requested Phase B via Nova action');

            // Dispatch job
            \App\Jobs\RunPhaseBJob::dispatch($task->id);

            return Action::message('✅ Phase B queued. Check Activity Timeline for progress.');
        }

        return Action::message('Phase B queued.');
    }
}


