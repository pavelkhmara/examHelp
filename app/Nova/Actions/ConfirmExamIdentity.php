<?php

namespace App\Nova\Actions;

use App\Models\Exam;
use App\Models\GenerationTask;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Collection;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Http\Requests\NovaRequest;
use Eminiarts\NovaRadioField\Radio; // пакет радиокнопок
use Laravel\Nova\Fields\Select; // fallback, если без пакета

class ConfirmExamIdentity extends Action
{
    use Queueable;

    public $name = 'Подтвердить идентичность и продолжить';

    public $uriKey = 'confirm-exam-identity';

    public function fields(NovaRequest $request): array
    {
        /** @var Exam $exam */
        $exam = Exam::findOrFail($request->resourceId);
        $candidates = collect(data_get($exam->identity, 'candidates', []));

        // Собираем подписи для вариантов
        $options = $candidates->mapWithKeys(function ($c, $i) {
            $id = data_get($c, 'id', (string) $i);
            $label = sprintf(
                '%s — %s — %s — lang:%s — conf:%.2f',
                data_get($c, 'canonical.name') ?? '—',
                data_get($c, 'canonical.provider') ?? '—',
                data_get($c, 'canonical.variant') ?? '—',
                data_get($c, 'canonical.language_of_test') ?? '—',
                (float) data_get($c, 'confidence', 0)
            );
            return [$id => $label];
        })->all();

        // === Красиво (радиокнопки), если пакет установлен ===
        // return [
        //     Radio::make('Выберите вариант', 'candidate_id')
        //         ->options($options)
        //         ->stack() // вертикально
        //         ->rules('required'),
        //     Textarea::make('Комментарий (необязательно)', 'note')->alwaysShow(),
        // ];

        // === Без пакетов: Select как fallback ===
        return [
            Select::make('Выберите вариант', 'candidate_id')
                ->options($options)->displayUsingLabels()->rules('required'),
            Textarea::make('Комментарий (необязательно)', 'note')->alwaysShow(),
        ];
    }

    /**
     * @param  \Laravel\Nova\Fields\ActionFields  $fields
     * @param  \Illuminate\Support\Collection<int,\App\Models\Exam>  $models
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        /** @var Exam $exam */
        $exam = $models->first();

        $identity = $exam->identity ?? [];
        $candidates = collect(data_get($identity, 'candidates', []));

        $selectedId = (string) $fields->get('candidate_id');
        $selected = $candidates->first(function ($c, $idx) use ($selectedId) {
            return data_get($c, 'id', (string) $idx) === $selectedId;
        });

        if (!$selected) {
            return Action::danger('Не найден выбранный вариант.');
        }

        // Обновляем идентичность и статусы
        $identity['status'] = 'confirmed';
        $identity['confirmed_candidate_id'] = $selectedId;
        $identity['canonical'] = data_get($selected, 'canonical');
        if ($note = (string) $fields->get('note')) {
            $identity['human_note'] = $note;
        }

        $exam->identity = $identity;
        $exam->analysis_status = 'identity_confirmed';
        $exam->save();

        // Создаём таск на продолжение пайплайна (очередь-first)
        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research.continue',
            'status' => 'queued',
            'request' => [
                'reason' => 'identity_confirmed_by_user',
                'candidate' => $selected,
            ],
            'idempotency_key' => 'continue:'.$exam->id.':'.$selectedId,
        ]);

        $task->addActivity(
            'identity_confirmed',
            'Пользователь подтвердил вариант; пайплайн продолжится.',
            ['candidate_id' => $selectedId]
        );

        // Если есть ваш Job/Service — дерните тут:
        \App\Jobs\RunExamResearchJob::dispatch($task->id);

        return Action::message('Готово: идентичность подтверждена, пайплайн продолжен.');
    }
}
