<?php

namespace App\Nova\Actions;

use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\ExamResearchService;
use App\Services\LanguageApp\Validators\QuestionTypeContract;
use Illuminate\Bus\Queueable;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class ImportAiStructure extends Action
{
    use Queueable;

    public $name = 'Import AI Structure';

    public function fields(NovaRequest $request)
    {
        return [
            Boolean::make('Take Latest Task', 'take_latest')
                ->help('Если включено — возьмём JSON из последней завершенной GenerationTask этого экзамена.')
                ->default(true),
            Textarea::make('Paste JSON (optional)', 'json')
                ->help('Если выключить флаг выше — вставь сюда сырой JSON ответа от AI. Оставь пустым, чтобы использовать latest task.'),
        ];
    }

    public function handle(ActionFields $fields, $models)
    {
        /** @var ExamResearchService $svc */
        $svc = app(ExamResearchService::class);
        $validator = app(QuestionTypeContract::class);

        foreach ($models as $exam) {
            /** @var Exam $exam */
            $json = null;

            if ($fields->get('take_latest')) {
                $task = GenerationTask::query()
                    ->where('exam_id', $exam->id)
                    ->where('status', 'completed')
                    ->latest('id')
                    ->first();

                if (! $task || empty($task->result)) {
                    return Action::danger('No completed GenerationTask with result for this exam.');
                }

                $json = $task->result; // уже массив, если ты так сохраняешь
            } else {
                $raw = (string) ($fields->get('json'));
                if (! strlen(trim($raw))) {
                    return Action::danger('Пустой JSON.');
                }
                $json = json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return Action::danger('Provided JSON is invalid: '.json_last_error_msg());
                }
            }

            // VALIDATE tasks with strict whitelist — reject on any unknowns
            $invalid = [];
            $normalizedTasks = [];
            $tasks = $json['tasks'] ?? ($json['examples'] ?? []); // поддерживаем оба варианта структуры
            foreach ($tasks as $tIdx => $task) {
                try {
                    $normalizedTasks[] = $validator->validateTask($task);
                } catch (\Illuminate\Validation\ValidationException $ve) {
                    $invalid[] = ['index' => $tIdx, 'errors' => $ve->errors()];
                }
            }
            if (! empty($invalid)) {
                return Action::danger('Import rejected: some tasks have invalid/unknown types. Details: '.json_encode($invalid, JSON_UNESCAPED_UNICODE));
            }

            try {
                $svc->importAiJson($exam, $normalizedTasks);
            } catch (\Throwable $e) {
                return Action::danger('Импорт не удался: '.$e->getMessage());
            }
        }

        return Action::message('Validated and imported.');
    }
}
