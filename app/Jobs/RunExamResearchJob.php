<?php

namespace App\Jobs;

use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\ExamResearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunExamResearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $taskId) {}

    public function handle(ExamResearchService $svc): void
    {
        $task = GenerationTask::query()->findOrFail($this->taskId);
        /** @var Exam $exam */
        $exam = Exam::query()->findOrFail($task->exam_id);

        // CRITICAL: If task is already in pending_confirmation or pending_clarification, STOP immediately
        // This prevents duplicate execution when Job is dispatched multiple times
        if (in_array($task->status, ['pending_confirmation', 'pending_clarification'], true)) {
            \Illuminate\Support\Facades\Log::info('Job stopped: task already waiting for user input', [
                'task_id' => $task->id,
                'status' => $task->status,
            ]);

            return;
        }

        // Check if identity stage was already run
        $identityResult = $task->result['identity'] ?? null;

        // If user provided clarification, re-run identity_guard with updated data
        $needsRerun = false;
        if ($identityResult && ($identityResult['user_provided_clarification'] ?? false)) {
            $needsRerun = true;
            \Illuminate\Support\Facades\Log::info('Re-running identity_guard with user-provided clarification', [
                'task_id' => $task->id,
                'clarification_data' => $identityResult['clarification_data'] ?? [],
            ]);
        }

        if (! $identityResult || $needsRerun) {
            // S1: Run identity_guard (first time or after clarification)
            $identityResult = $svc->runIdentityGuard($exam, $task);
            $task->refresh();

            // S1.5: If confidence is 0.90-0.96, run confidence_boost
            if (($identityResult['needs_confidence_boost'] ?? false) === true) {
                $identityResult = $svc->runConfidenceBoost($exam, $task, $identityResult);

                // Update task with boosted identity
                $result = (array) ($task->result ?? []);
                $result['identity'] = $identityResult;
                $task->result = $result;
                $task->save();
                $task->refresh();
            }
        }

        // Check if we have a hold - if yes, STOP here and wait for confirmation
        // UNLESS without_confirmation is true
        $withoutConfirmation = (bool) ($task->request['without_confirmation'] ?? false);

        if (($identityResult['hold'] ?? false) === true) {
            if ($withoutConfirmation) {
                // Skip hold - auto-confirm with AI reasoning
                \Illuminate\Support\Facades\Log::info('Skipping hold due to without_confirmation=true', [
                    'exam_id' => $exam->id,
                    'task_id' => $task->id,
                ]);

                $identityResult['hold'] = false;
                $identityResult['auto_confirmed'] = true;
                $identityResult['auto_confirmed_at'] = now()->toISOString();
                $identityResult['disclaimer'] = 'Identity auto-confirmed by AI without user input. Confidence: '.($identityResult['confidence'] ?? 0);

                // Update task result
                $result = (array) ($task->result ?? []);
                $result['identity'] = $identityResult;
                $task->result = $result;
                $task->save();
                // Continue to pipeline
            } else {
                // Normal hold - wait for user confirmation
                $task->status = 'pending_confirmation';
                $task->save();

                $exam->research_status = 'queued';
                $exam->save();

                \Illuminate\Support\Facades\Log::info('Research pipeline paused for identity confirmation', [
                    'exam_id' => $exam->id,
                    'task_id' => $task->id,
                    'identity' => $identityResult,
                ]);

                // STOP - do not continue pipeline until user confirms
                return;
            }
        }

        // If identity is uncertain with low confidence, run variant_probe
        if (
            ($identityResult['status'] ?? '') === 'uncertain'
            || ($identityResult['confidence'] ?? 1.0) < 0.80
        ) {
            $variantResult = $svc->runVariantProbe($exam, $task, $identityResult);

            // If disambiguation is needed, stop and wait for user input
            // UNLESS without_confirmation is true
            if ($variantResult['disambiguation_needed'] ?? false) {
                if ($withoutConfirmation) {
                    // Run AI auto-clarification instead of waiting for user
                    \Illuminate\Support\Facades\Log::info('Running AI auto-clarification due to without_confirmation=true', [
                        'exam_id' => $exam->id,
                        'task_id' => $task->id,
                    ]);

                    $autoClarification = $svc->runAutoClarification($exam, $task, $identityResult);

                    // Merge auto-clarified data into user_input
                    $currentInput = $task->request['user_input'] ?? [];
                    $inferredData = $autoClarification['inferred_data'] ?? [];
                    $mergedInput = array_merge($currentInput, $inferredData);

                    // Update task request
                    $request = (array) ($task->request ?? []);
                    $request['user_input'] = $mergedInput;
                    $task->request = $request;

                    // Mark identity as auto-clarified
                    $identityResult['auto_clarified'] = true;
                    $identityResult['auto_clarified_at'] = now()->toISOString();
                    $identityResult['auto_clarified_data'] = $inferredData;
                    $identityResult['disclaimer'] = $autoClarification['disclaimer'] ?? 'AI-inferred data used for structure generation';
                    $identityResult['ai_reasoning'] = $autoClarification['reasoning'] ?? null;

                    // Update result and re-run identity guard with new data
                    $result = (array) ($task->result ?? []);
                    $result['identity'] = $identityResult;
                    $task->result = $result;
                    $task->save();

                    // Re-run identity guard with merged data
                    $identityResult = $svc->runIdentityGuard($exam, $task);
                    $task->refresh();

                    // Continue with pipeline
                } else {
                    // Normal flow - wait for user
                    $task->status = 'pending_clarification';
                    $task->save();

                    $exam->research_status = 'queued';
                    $exam->save();

                    \Illuminate\Support\Facades\Log::info('Research pipeline paused for variant clarification', [
                        'exam_id' => $exam->id,
                        'task_id' => $task->id,
                        'variant_result' => $variantResult,
                    ]);

                    return;
                }
            }
        }

        // Identity confirmed or certain enough - proceed with main pipeline
        $task->status = 'running';
        $task->save();

        $exam->research_status = 'running_overview'; // Use existing enum value
        $exam->save();

        // Save identity snapshot before running structure pipeline
        $identitySnapshot = $task->result['identity'] ?? null;

        // Run the main structure building pipeline
        $pipelineResult = $svc->runPipeline($exam, $task);

        // Restore identity result if pipeline overwrote it
        $task->refresh();
        if (! isset($task->result['identity']) && is_array($identitySnapshot)) {
            $result = (array) ($task->result ?? []);
            $result['identity'] = $identitySnapshot;
            $task->result = $result;
            $task->save();
        }

        // Run sanity checker on the generated structure
        // Build exam_doc_draft from pipeline result and identity
        $overview = $pipelineResult['overview'] ?? [];
        $structure = $pipelineResult['structure'] ?? [];

        // Try to get sections from various possible locations
        $sections = $overview['sections'] ?? $structure['sections'] ?? [];

        // Build exam_doc_draft even if we don't have perfect data
        if ($identitySnapshot || ! empty($sections)) {
            $examDocDraft = [
                'canonical' => $identitySnapshot['canonical'] ?? [],
                'sections' => $sections,
                'administration' => [
                    'total_time_minutes' => $overview['total_exam_duration'] ?? $structure['total_exam_duration'] ?? null,
                ],
                'scoring' => [
                    'scale' => $overview['scoring_scale'] ?? null,
                ],
            ];

            $sanityResult = $svc->runSanityChecker($exam, $task, $examDocDraft);

            // If compliance is too low, add warning to result
            if (($sanityResult['compliance_score'] ?? 0) < 0.85) {
                $result = (array) ($task->result ?? []);
                $result['sanity_check'] = $sanityResult;
                $result['warnings'] = array_merge(
                    $result['warnings'] ?? [],
                    ['Low compliance with exam family invariants: '.($sanityResult['compliance_score'] ?? 0)]
                );
                $task->result = $result;
                $task->save();

                \Illuminate\Support\Facades\Log::warning('Sanity check compliance below threshold', [
                    'exam_id' => $exam->id,
                    'task_id' => $task->id,
                    'compliance' => $sanityResult['compliance_score'] ?? 0,
                    'fails' => $sanityResult['fails'] ?? [],
                ]);
            }
        }
    }
}
