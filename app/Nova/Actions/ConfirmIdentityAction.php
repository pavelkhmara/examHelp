<?php

namespace App\Nova\Actions;

use App\Models\Exam;
use App\Models\GenerationTask;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class ConfirmIdentityAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Confirm/Reject Identity';

    public $uriKey = 'confirm-reject-identity';

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
                ->whereIn('status', ['pending_confirmation', 'pending_clarification'])
                ->latest()
                ->first();

            if (! $task) {
                return Action::danger('No pending identity confirmation for this exam.');
            }

            // Extract identity from new structure (verification_attempts) or fallback to old
            $result = $task->result ?? [];
            $identity = null;

            if (isset($result['verification_attempts']) && ! empty($result['verification_attempts'])) {
                $latestAttempt = end($result['verification_attempts']);
                $identity = $latestAttempt['identity_result'] ?? null;
            } else {
                $identity = $result['identity'] ?? null;
            }

            if (! $identity) {
                return Action::danger('No identity data found for this exam.');
            }

            // Check if task is in pending_confirmation status
            if ($task->status !== 'pending_confirmation') {
                return Action::danger('This task is not in pending_confirmation status (current: '.$task->status.').');
            }

            $confirmed = $fields->get('confirmed');
            $notes = $fields->get('notes');

            if ($confirmed) {
                // User confirmed - boost confidence and continue pipeline
                $originalConfidence = $identity['confidence'] ?? 0;
                $identity['user_confirmed'] = true;
                $identity['confirmed_at'] = now()->toISOString();

                // CRITICAL: User confirmation overrides low confidence
                // Set confidence to 1.0 (100%) since user manually verified
                if ($originalConfidence < 0.8) {
                    $identity['confidence'] = 1.0;
                    $identity['confidence_boosted_by'] = 'user_confirmation';
                    $identity['original_confidence'] = $originalConfidence;
                }

                if ($notes) {
                    $identity['confirmation_notes'] = $notes;
                }

                // Update identity in verification_attempts structure
                $result = (array) ($task->result ?? []);
                if (isset($result['verification_attempts']) && ! empty($result['verification_attempts'])) {
                    $attemptIndex = count($result['verification_attempts']) - 1;
                    $result['verification_attempts'][$attemptIndex]['identity_result'] = $identity;
                } else {
                    // Fallback to old structure
                    $result['identity'] = $identity;
                }

                $task->result = $result;
                $task->status = 'queued'; // Reset to queued so job can pick it up
                $task->save();

                // Phase 9: Create ConfirmedIdentity for future reuse
                try {
                    /** @var \App\Services\LanguageApp\ConfirmedIdentityService $confirmedIdentityService */
                    $confirmedIdentityService = app(\App\Services\LanguageApp\ConfirmedIdentityService::class);
                    $confirmedIdentity = $confirmedIdentityService->createConfirmedIdentity(
                        $exam,
                        $identity,
                        $task
                    );

                    $task->addActivity('user_confirmed_identity', 'User confirmed identity via Nova action', [
                        'confirmed_identity_id' => $confirmedIdentity->id,
                        'original_confidence' => $originalConfidence,
                        'notes' => $notes,
                    ]);

                    \Illuminate\Support\Facades\Log::info('ConfirmedIdentity created from user confirmation', [
                        'exam_id' => $exam->id,
                        'task_id' => $task->id,
                        'confirmed_identity_id' => $confirmedIdentity->id,
                    ]);
                } catch (\Throwable $e) {
                    // Log error but don't fail the action
                    \Illuminate\Support\Facades\Log::error('Failed to create ConfirmedIdentity in ConfirmIdentityAction', [
                        'exam_id' => $exam->id,
                        'task_id' => $task->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Continue the pipeline
                \App\Jobs\RunExamResearchJob::dispatch($task->id)
                    ->delay(now()->addSeconds(1));

                return Action::message('Identity confirmed! Research pipeline will continue.');
            } else {
                // User rejected - re-run identity verification
                $identity['status'] = 'uncertain';
                $identity['confidence'] = 0.3;
                $identity['user_rejected'] = true;
                $identity['rejected_at'] = now()->toISOString();
                $identity['user_provided_clarification'] = true; // Signal to job to re-run identity guard
                if ($notes) {
                    $identity['rejection_notes'] = $notes;
                    $identity['clarification_data'] = ['user_notes' => $notes];
                }

                // Update identity in verification_attempts structure
                $result = (array) ($task->result ?? []);
                if (isset($result['verification_attempts']) && ! empty($result['verification_attempts'])) {
                    $attemptIndex = count($result['verification_attempts']) - 1;
                    $result['verification_attempts'][$attemptIndex]['identity_result'] = $identity;
                } else {
                    // Fallback to old structure
                    $result['identity'] = $identity;
                }

                $task->result = $result;
                $task->status = 'queued'; // Set to queued so job can pick it up
                $task->save();

                // Re-run the research job to verify identity again
                \App\Jobs\RunExamResearchJob::dispatch($task->id)
                    ->delay(now()->addSeconds(1));

                return Action::message('Identity rejected. Re-running identity verification with your notes.');
            }
        }

        return Action::message('Identity confirmation processed.');
    }

    /**
     * Get the fields available on the action.
     */
    public function fields(NovaRequest $request)
    {
        return [
            Boolean::make('Confirmed', 'confirmed')
                ->help('Check this if the identified exam is correct, leave unchecked to reject'),

            Textarea::make('Notes', 'notes')
                ->help('Optional notes about your decision')
                ->rows(3),
        ];
    }
}
