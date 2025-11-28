<?php

namespace App\Nova\Actions;

use App\Jobs\SynthesizeQuestionsJob;
use App\Models\GenerationPlan;
use App\Models\GenerationTask;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;

class SynthesizeQuestionsAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = '4️⃣ Synthesize Questions';

    public $uriKey = 'synthesize-questions';

    /**
     * Determine if the action should be available for the given request.
     *
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
            // Validate that generation plans exist
            $plans = GenerationPlan::where('exam_id', $exam->id)->get();
            if ($plans->isEmpty()) {
                return Action::danger('❌ No generation plans found. Run "3️⃣ Resolve Plans" first.');
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

            return Action::message("✅ Question synthesis queued ({$plans->count()} sections, {$plans->sum('total_questions')} questions total). Check Activity Timeline for progress.");
        }

        return Action::message('Question synthesis queued.');
    }
}
