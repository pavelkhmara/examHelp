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

/**
 * Generate Example Questions Action
 *
 * Creates sample questions for each question archetype in the exam structure.
 * Requires: structure_v2 with sections containing question_archetypes.
 */
class GenerateExamplesAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = '3.5️⃣ Generate Examples';

    public $uriKey = 'generate-examples';

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
            $structure = $exam->structure_v2;
            if (! $structure) {
                return Action::danger('❌ structure_v2 is required. Run "1️⃣ Phase A: Skeleton" first.');
            }

            // Check if any section has archetypes
            $hasArchetypes = false;
            $sections = $structure['sections'] ?? [];
            foreach ($sections as $section) {
                if (! empty($section['question_archetypes'])) {
                    $hasArchetypes = true;
                    break;
                }
            }

            if (! $hasArchetypes) {
                return Action::danger('❌ No question archetypes found. Complete "2️⃣ Phase B" → "3️⃣ Resolve Plans" first.');
            }

            // Create generation task
            $task = GenerationTask::create([
                'exam_id' => $exam->id,
                'type' => 'generate_examples',
                'status' => 'queued',
                'request' => [
                    'examples_per_archetype' => 1,
                ],
            ]);

            $task->addActivity('examples_queued', 'Operator requested example generation via Nova action');

            // Dispatch job
            \App\Jobs\GenerateExamplesJob::dispatch($task->id);

            return Action::message('✅ Example generation queued. Check Activity Timeline for progress.');
        }

        return Action::message('Example generation queued.');
    }
}
