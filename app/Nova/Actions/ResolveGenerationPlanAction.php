<?php

namespace App\Nova\Actions;

use App\Services\LanguageApp\AssemblyResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;

class ResolveGenerationPlanAction extends Action implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public $name = 'Resolve Generation Plans (v2)';

    public function handle(ActionFields $fields, Collection $models)
    {
        /** @var \App\Models\Exam $exam */
        foreach ($models as $exam) {
            try {
                $resolver = app(AssemblyResolver::class);
                $plans = $resolver->resolve($exam);
                return Action::message('Resolved '.count($plans).' plans.');
            } catch (\Throwable $e) {
                return Action::danger('Plan resolution failed: '.$e->getMessage());
            }
        }

        return Action::message('No exams selected.');
    }
}


