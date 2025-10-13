<?php

namespace App\Nova\Actions;

use App\Jobs\RunExamResearchJob;
use Illuminate\Bus\Queueable;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class ResearchAction extends Action
{
    use Queueable;

    public $name = 'Run Research Pipeline';

    public function fields(NovaRequest $request)
    {
        return [ Textarea::make('Notes')->help('Optional context/hints') ];
    }

    public function handle(ActionFields $fields, $models)
    {
        foreach ($models as $exam) {
            RunExamResearchJob::dispatch($exam->id, (string)($fields->get('Notes') ?? null));
        }
        return Action::message('Research queued.');
    }
}
