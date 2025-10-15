<?php

namespace App\Nova\Actions;

use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\ExamResearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
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

        foreach ($models as $exam) {
            /** @var Exam $exam */

            $ai = null;

            if ($fields->get('take_latest')) {
                $task = GenerationTask::query()
                    ->where('exam_id', $exam->id)
                    ->where('status', 'completed')
                    ->latest('id')
                    ->first();

                if (!$task || empty($task->result)) {
                    return Action::danger('Нет завершённой задачи с result для этого экзамена.');
                }

                $ai = $task->result; // уже массив, если ты так сохраняешь
            } else {
                $raw = (string)($fields->get('json'));
                if (!strlen(trim($raw))) {
                    return Action::danger('Пустой JSON.');
                }
                $ai = json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return Action::danger('Некорректный JSON: '.json_last_error_msg());
                }
            }

            try {
                $svc->importAiJson($exam, $ai);
            } catch (\Throwable $e) {
                return Action::danger('Импорт не удался: '.$e->getMessage());
            }
        }

        return Action::message('Импорт выполнен. Источники и категории обновлены.');
    }
}
