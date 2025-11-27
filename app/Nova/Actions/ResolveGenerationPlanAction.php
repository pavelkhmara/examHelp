<?php

namespace App\Nova\Actions;

use App\Jobs\ResolveGenerationPlansJob;
use App\Models\GenerationTask;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;

class ResolveGenerationPlanAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = '3️⃣ Resolve Plans';

    public $uriKey = 'resolve-generation-plans-v2';

    public $standalone = false;

    public $showOnDetail = true;

    /**
     * Determine if the action should be available for the given request.
     *
     * @return bool
     */
    public function authorizedToSee(\Illuminate\Http\Request $request)
    {
        \Log::info('[ResolveGenerationPlanAction] authorizedToSee called', [
            'resourceId' => $request->resourceId,
            'route' => $request->route()->getName(),
            'uri' => $request->getRequestUri(),
        ]);

        // Nova вызывает authorizedToSee БЕЗ resourceId для первичной проверки
        // Возвращаем true, чтобы action был виден в UI
        // Реальная валидация происходит в handle()
        if (! $request->resourceId) {
            \Log::info('[ResolveGenerationPlanAction] authorizedToSee: NO resourceId, returning TRUE (Nova initial check)');

            return true; // CHANGED: было false
        }

        // Если есть resourceId - проверяем, что экзамен существует
        $exam = \App\Models\Exam::find($request->resourceId);
        $result = (bool) $exam;

        \Log::info('[ResolveGenerationPlanAction] authorizedToSee result', [
            'exam_found' => $result,
            'exam_id' => $exam->id ?? null,
        ]);

        return $result;
    }

    /**
     * Determine if the user is authorized to run the action.
     *
     * @return bool
     */
    public function authorizedToRun(\Illuminate\Http\Request $request, $model)
    {
        \Log::info('[ResolveGenerationPlanAction] authorizedToRun called', [
            'user' => $request->user()->email ?? 'no-user',
            'model_id' => $model->id ?? 'no-model',
            'model_type' => get_class($model),
            'uri' => $request->getRequestUri(),
            'method' => $request->method(),
            'result' => true,
        ]);

        return true;
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        \Log::info('[ResolveGenerationPlanAction] handle() STARTED', [
            'models_count' => $models->count(),
            'timestamp' => now()->toDateTimeString(),
        ]);

        /** @var \App\Models\Exam $exam */
        foreach ($models as $exam) {
            \Log::info('[ResolveGenerationPlanAction] Processing exam', [
                'exam_id' => $exam->id,
                'exam_title' => $exam->title,
                'research_status' => $exam->research_status,
            ]);

            // Validate that structure_v2 exists (from Phase A)
            $structure = $exam->structure_v2 ?? null;
            if (! $structure) {
                return Action::danger('❌ Phase A not completed. Run "1️⃣ Phase A: Skeleton" first.');
            }

            // Check if at least one section has assembly (from Phase B)
            $sections = $structure['sections'] ?? [];
            $hasAssembly = false;
            foreach ($sections as $section) {
                if (! empty($section['assembly'])) {
                    $hasAssembly = true;
                    break;
                }
            }

            if (! $hasAssembly) {
                return Action::danger('❌ Phase B not completed. Run "2️⃣ Phase B: Assembly" first.');
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

            return Action::message('✅ Plan resolution queued. Check Activity Timeline for progress.');
        }

        return Action::message('Plan resolution queued.');
    }
}
