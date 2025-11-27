<?php

namespace App\Nova\Actions;

use App\Models\Exam;
use App\Models\GenerationTask;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;

class ConfidenceBoostAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Boost Confidence';

    public $uriKey = 'boost-confidence';

    /**
     * Perform the action on the given models.
     */
    public function authorizedToRun(\Illuminate\Http\Request $request, $model)
    {
        return true;
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        foreach ($models as $exam) {
            /** @var Exam $exam */
            $task = GenerationTask::query()
                ->where('exam_id', $exam->id)
                ->whereIn('status', ['pending_confirmation', 'queued', 'running'])
                ->latest()
                ->first();

            if (! $task) {
                return Action::danger('No active task found for this exam.');
            }

            $identity = $task->result['identity'] ?? null;
            if (! $identity) {
                return Action::danger('No identity data found for this exam.');
            }

            $confidence = $identity['confidence'] ?? 0.0;

            // Check if confidence boost is applicable (0.70 <= confidence < 0.8)
            if ($confidence < 0.70) {
                return Action::danger("Confidence too low ({$confidence}) - cannot boost. Please confirm identity manually or re-run verification.");
            }

            if ($confidence >= 0.8) {
                return Action::danger("Confidence already high ({$confidence}) - boost not needed.");
            }

            // Check if already boosted
            if (isset($identity['confidence_boosted_at'])) {
                return Action::danger('Confidence has already been boosted. Use "Confirm Identity" instead.');
            }

            // Create new task for confidence boost
            $boostTask = GenerationTask::create([
                'exam_id' => $exam->id,
                'type' => 'confidence_boost',
                'status' => 'queued',
                'request' => [
                    'triggered_by' => 'operator_manual',
                    'original_confidence' => $confidence,
                ],
                'result' => [
                    'identity' => $identity, // Pass current identity
                ],
            ]);

            $boostTask->addActivity('confidence_boost_queued', 'Operator requested confidence boost via Nova action', [
                'original_confidence' => $confidence,
            ]);

            // Dispatch job
            \App\Jobs\RunConfidenceBoostJob::dispatch($boostTask->id);

            return Action::message("Confidence boost queued (current: {$confidence}). Check Activity Timeline for progress.");
        }

        return Action::message('Confidence boost queued.');
    }

    /**
     * Get the fields available on the action.
     */
    public function fields(NovaRequest $request)
    {
        return [];
    }
}
