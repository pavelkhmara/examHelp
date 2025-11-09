<?php

namespace App\Nova\Actions;

use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\ExamResearchService;
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

    /**
     * Perform the action on the given models.
     */
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

            // Check if confidence boost is applicable (0.80 <= confidence < 0.97)
            if ($confidence < 0.80) {
                return Action::danger("Confidence too low ({$confidence}) - cannot boost. Please confirm identity manually or re-run verification.");
            }

            if ($confidence >= 0.97) {
                return Action::danger("Confidence already high ({$confidence}) - boost not needed.");
            }

            // Check if already boosted
            if (isset($identity['confidence_boosted_at'])) {
                return Action::danger('Confidence has already been boosted. Use "Confirm Identity" instead.');
            }

            // Run confidence boost
            try {
                /** @var ExamResearchService $svc */
                $svc = app(ExamResearchService::class);

                $task->addActivity('confidence_boost_manual', 'Operator requested confidence boost via Nova action', [
                    'original_confidence' => $confidence,
                ]);

                $task->status = 'running';
                $task->save();

                $exam->research_status = 'running_overview';
                $exam->save();

                // Run confidence boost
                $boostedIdentity = $svc->runConfidenceBoost($exam, $task, $identity);

                // Update task with boosted identity
                $result = (array) ($task->result ?? []);
                $result['identity'] = $boostedIdentity;
                $task->result = $result;
                $task->save();
                $task->refresh();

                // Update exam->identity with boosted confidence
                $exam->identity = $boostedIdentity;
                $exam->save();

                $boostedConfidence = $boostedIdentity['confidence'] ?? 0.0;
                $task->addActivity('confidence_boost_completed', "Confidence boosted to: {$boostedConfidence}", [
                    'original_confidence' => $confidence,
                    'boosted_confidence' => $boostedConfidence,
                    'triggered_by' => 'operator_manual',
                ]);

                // If confidence is now >= 0.97, continue pipeline
                if ($boostedConfidence >= 0.97) {
                    $task->status = 'queued';
                    $task->save();

                    // Continue the pipeline
                    \App\Jobs\RunExamResearchJob::dispatch($task->id)
                        ->delay(now()->addSeconds(1));

                    return Action::message("Confidence boosted from {$confidence} to {$boostedConfidence}! Pipeline will continue.");
                } else {
                    // Still below threshold - set to pending_confirmation
                    $task->status = 'pending_confirmation';
                    $task->save();

                    $exam->research_status = 'queued';
                    $exam->save();

                    return Action::message("Confidence boosted to {$boostedConfidence}, but still below 0.97. Please confirm identity manually.");
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Confidence boost failed', [
                    'exam_id' => $exam->id,
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $task->addActivity('confidence_boost_failed', 'Confidence boost failed: '.$e->getMessage());
                $task->status = 'failed';
                $task->error = 'Confidence boost failed: '.$e->getMessage();
                $task->save();

                $exam->research_status = 'failed';
                $exam->save();

                return Action::danger('Confidence boost failed: '.$e->getMessage());
            }
        }

        return Action::message('Confidence boost completed.');
    }

    /**
     * Get the fields available on the action.
     */
    public function fields(NovaRequest $request)
    {
        return [];
    }
}
