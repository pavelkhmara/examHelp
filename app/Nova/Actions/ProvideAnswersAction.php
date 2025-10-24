<?php

namespace App\Nova\Actions;

use App\Models\Exam;
use App\Models\GenerationTask;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class ProvideAnswersAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Provide Answers to AI Questions';

    /**
     * Perform the action on the given models.
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        foreach ($models as $exam) {
            /** @var Exam $exam */
            $task = GenerationTask::query()
                ->where('exam_id', $exam->id)
                ->whereIn('status', ['pending_confirmation', 'pending_clarification'])
                ->latest()
                ->first();

            if (! $task) {
                return Action::danger('No pending task for this exam that needs clarification.');
            }

            $identity = $task->result['identity'] ?? null;
            if (! $identity) {
                return Action::danger('No identity data found for this exam.');
            }

            $answersJson = $fields->get('answers_json');
            if (empty($answersJson)) {
                return Action::danger('Please provide answers in JSON format.');
            }

            // Валидируем JSON
            try {
                $answers = json_decode($answersJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return Action::danger('Invalid JSON format: '.$e->getMessage());
            }

            if (! is_array($answers)) {
                return Action::danger('Answers must be a JSON object.');
            }

            // Объединяем существующий user_input с новыми ответами
            $currentInput = $task->request['user_input'] ?? [];
            if (is_string($currentInput)) {
                $currentInput = json_decode($currentInput, true) ?? [];
            }

            // Мержим ответы
            $updatedInput = array_merge($currentInput, $answers);

            // Обновляем request в задаче
            $request = (array) ($task->request ?? []);
            $request['user_input'] = $updatedInput;
            $task->request = $request;

            // Отмечаем что пользователь предоставил ответы
            $identity['user_provided_clarification'] = true;
            $identity['clarification_provided_at'] = now()->toISOString();
            $identity['clarification_data'] = $answers;

            // Сбрасываем статус задачи для повторного запуска
            $result = (array) ($task->result ?? []);
            $result['identity'] = $identity;
            $task->result = $result;
            $task->status = 'queued';  // Вернем в очередь для повторной обработки
            $task->save();

            // Перезапускаем pipeline с новыми данными
            \App\Jobs\RunExamResearchJob::dispatch($task->id)
                ->delay(now()->addSeconds(1));

            return Action::message('Answers provided! Research pipeline will continue with updated information.');
        }

        return Action::message('Answers processed.');
    }

    /**
     * Get the fields available on the action.
     */
    public function fields(NovaRequest $request)
    {
        // Получаем exam из контекста
        $exam = null;
        if ($request->resources) {
            $examId = $request->resources;
            $exam = Exam::find($examId);
        }

        $helpText = 'Provide answers as JSON object. Example:
{
  "provider": "British Council",
  "where": {
    "modality": "test_center"
  },
  "target": {
    "score": "7.0"
  }
}';

        // Если есть экзамен и задача, покажем что именно нужно
        if ($exam) {
            $task = GenerationTask::query()
                ->where('exam_id', $exam->id)
                ->whereIn('status', ['pending_confirmation', 'pending_clarification'])
                ->latest()
                ->first();

            if ($task && isset($task->result['identity'])) {
                $identity = $task->result['identity'];
                $needFields = $identity['need_fields'] ?? [];

                if (! empty($needFields)) {
                    $helpText = 'Missing fields: '.implode(', ', $needFields)."\n\n".$helpText;
                }

                $followups = $identity['followups'] ?? [];
                if (! empty($followups)) {
                    $helpText .= "\n\nQuestions:\n".implode("\n", array_map(fn ($q, $i) => ($i + 1).". $q", $followups, array_keys($followups)));
                }
            }
        }

        return [
            Textarea::make('Answers (JSON)', 'answers_json')
                ->rules('required', 'string')
                ->help($helpText)
                ->rows(12),
        ];
    }

    /**
     * Показывать только для экзаменов с pending задачами
     */
    public function authorizedToSee(\Illuminate\Http\Request $request)
    {
        // Если это страница детали экзамена
        if ($request->route('resource') === 'exams' && $request->route('resourceId')) {
            $exam = Exam::find($request->route('resourceId'));
            if ($exam) {
                $hasPendingTask = GenerationTask::query()
                    ->where('exam_id', $exam->id)
                    ->whereIn('status', ['pending_confirmation', 'pending_clarification'])
                    ->exists();

                return $hasPendingTask;
            }
        }

        return parent::authorizedToSee($request);
    }
}
