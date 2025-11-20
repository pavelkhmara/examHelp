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

    public $name = 'Generate Examples';

    /**
     * Determine if the action should be available for the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    public function authorizedToSee(\Illuminate\Http\Request $request)
    {
        // Only show if viewing a single exam resource
        if (! $request->route('resourceId')) {
            return false;
        }

        // Check if exam has structure_v2 with archetypes
        $exam = \App\Models\Exam::find($request->route('resourceId'));
        if (! $exam) {
            return false;
        }

        // Must have structure_v2
        $structure = $exam->meta['structure_v2'] ?? null;
        if (! $structure) {
            return false;
        }

        // At least one section must have question_archetypes
        $sections = $structure['sections'] ?? [];
        foreach ($sections as $section) {
            if (! empty($section['question_archetypes'])) {
                return true;
            }
        }

        return false;
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        /** @var \App\Models\Exam $exam */
        foreach ($models as $exam) {
            $structure = $exam->meta['structure_v2'] ?? null;
            if (! $structure) {
                return Action::danger('structure_v2 is required. Run Phase A first.');
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
                return Action::danger('No question archetypes found in structure_v2. Run Phase B first.');
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

            return Action::message('Example generation queued. Check Activity Timeline for progress.');
        }

        return Action::message('Example generation queued.');
    }
}
