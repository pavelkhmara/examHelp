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

class RunOverviewPhaseBAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Run Phase B (Assembly v2)';

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

        // Check if exam has Phase A skeleton
        $exam = \App\Models\Exam::find($request->route('resourceId'));
        if (! $exam) {
            return false;
        }

        // Must have structure_v2 (Phase A skeleton)
        $structure = $exam->meta['structure_v2'] ?? null;
        if (! $structure) {
            return false;
        }

        // Must have sections
        $sections = $structure['sections'] ?? [];
        if (empty($sections)) {
            return false;
        }

        // Check if any section is missing assembly (Phase B not done yet)
        // If all sections have assembly, no need to show this action
        $hasIncompleteSection = false;
        foreach ($sections as $section) {
            if (empty($section['assembly'])) {
                $hasIncompleteSection = true;
                break;
            }
        }

        return $hasIncompleteSection;
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        /** @var \App\Models\Exam $exam */
        foreach ($models as $exam) {
            $skeleton = $exam->structure_v2 ?? null;
            if (! $skeleton) {
                return Action::danger('Phase A structure_v2 is required before Phase B.');
            }

            $task = GenerationTask::create([
                'exam_id' => $exam->id,
                'type' => 'research_phase_b',
                'status' => 'queued',
                'request' => [],
            ]);

            $task->addActivity('phase_b_queued', 'Operator requested Phase B via Nova action');

            // Dispatch job
            \App\Jobs\RunPhaseBJob::dispatch($task->id);

            return Action::message('Phase B queued. Check Activity Timeline for progress.');
        }

        return Action::message('Phase B queued.');
    }
}


