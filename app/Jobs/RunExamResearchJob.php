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

        $task->addActivity('job_started', 'Research job started');
        $task->updateHeartbeat(); // Initial heartbeat

        // CRITICAL: If task is already in pending_confirmation or pending_clarification, STOP immediately
        // This prevents duplicate execution when Job is dispatched multiple times
        if (in_array($task->status, ['pending_confirmation', 'pending_clarification'], true)) {
            \Illuminate\Support\Facades\Log::info('Job stopped: task already waiting for user input', [
                'task_id' => $task->id,
                'status' => $task->status,
            ]);

            $task->addActivity('job_stopped_duplicate', 'Task already waiting for user input', [
                'status' => $task->status,
            ]);

            return;
        }

        // Check if identity stage was already run
        $identityResult = $task->result['identity'] ?? null;

        // If user confirmed identity, NEVER re-run identity_guard
        $userConfirmed = $identityResult['user_confirmed'] ?? false;
        if ($userConfirmed) {
            \Illuminate\Support\Facades\Log::info('Skipping identity_guard: user already confirmed', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
            ]);

            $task->addActivity('identity_confirmed_skip', 'Skipping identity check - user already confirmed');
        }

        // If user provided clarification, re-run identity_guard with updated data
        $needsRerun = false;
        if ($identityResult && ($identityResult['user_provided_clarification'] ?? false) && ! $userConfirmed) {
            $needsRerun = true;
            \Illuminate\Support\Facades\Log::info('Re-running identity_guard with user-provided clarification', [
                'task_id' => $task->id,
                'clarification_data' => $identityResult['clarification_data'] ?? [],
            ]);

            $task->addActivity('identity_rerun_clarification', 'Re-running identity check with user clarification');
        }

        if ((! $identityResult || $needsRerun) && ! $userConfirmed) {
            // Check if we should use iterative verification (default: true)
            $useIterativeVerification = $task->request['use_iterative_verification'] ?? true;

            if ($useIterativeVerification) {
                // S1: Run iterative identity verification (new method with web search)
                $task->addActivity('iterative_verification_started', 'Running iterative identity verification (max 3 attempts, 6 min timeout)');
                $task->updateHeartbeat(); // Heartbeat before long operation

                $identityResult = $svc->runIterativeIdentityVerification($exam, $task);
                $task->refresh();
                $task->updateHeartbeat(); // Heartbeat after identity verification

                // Check if verification failed
                if (($identityResult['status'] ?? '') === 'failed') {
                    $errorType = $identityResult['error'] ?? 'unknown';
                    $userMessage = $identityResult['user_message'] ?? 'Identity verification failed';

                    $task->addActivity('iterative_verification_failed', "Identity verification failed: {$errorType}", [
                        'error' => $errorType,
                        'user_message' => $userMessage,
                        'confidence' => $identityResult['confidence'] ?? 0,
                    ]);

                    // Set task to failed with user-friendly message
                    $task->status = 'failed';
                    $task->error = $userMessage;
                    $task->save();

                    $exam->research_status = 'failed';
                    $exam->save();

                    \Illuminate\Support\Facades\Log::warning('Iterative identity verification failed', [
                        'exam_id' => $exam->id,
                        'task_id' => $task->id,
                        'error_type' => $errorType,
                    ]);

                    // STOP - do not continue pipeline
                    return;
                }
            } else {
                // S1: Run identity_guard (first time or after clarification) - old single-attempt method
                $task->addActivity('identity_guard_started', 'Running identity verification stage');

                $identityResult = $svc->runIdentityGuard($exam, $task);
                $task->refresh();
            }

            $confidence = $identityResult['confidence'] ?? 0.0;
            $task->addActivity('identity_guard_completed', "Identity verified with confidence: {$confidence}", [
                'confidence' => $confidence,
                'status' => $identityResult['status'] ?? 'unknown',
            ]);

            // S1.5: If confidence is 0.90-0.96, run confidence_boost
            if (($identityResult['needs_confidence_boost'] ?? false) === true) {
                $task->addActivity('decision_point_confidence_boost', "Условие: 0.90 <= confidence < 0.97 (текущий: {$confidence}). Решение: запустить ConfidenceBoost для повышения уверенности", [
                    'condition' => '0.90 <= confidence < 0.97',
                    'confidence' => $confidence,
                    'decision' => 'run_confidence_boost',
                ]);
                $task->addActivity('confidence_boost_started', 'Running confidence boost stage');
                $task->updateHeartbeat(); // Heartbeat before boost

                $identityResult = $svc->runConfidenceBoost($exam, $task, $identityResult);

                // Update task with boosted identity
                $result = (array) ($task->result ?? []);
                $result['identity'] = $identityResult;
                $task->result = $result;
                $task->save();
                $task->refresh();

                $boostedConfidence = $identityResult['confidence'] ?? 0.0;
                $task->addActivity('confidence_boost_completed', "Confidence boosted to: {$boostedConfidence}", [
                    'confidence' => $boostedConfidence,
                ]);
                $task->updateHeartbeat(); // Heartbeat after boost
            }

            // CRITICAL: After identity guard (and optional boost), check if confidence is acceptable
            // If confidence < 0.97, we MUST stop and request user confirmation
            // UNLESS user already confirmed (from ConfirmIdentityAction) OR without_confirmation=true
            $finalConfidence = $identityResult['confidence'] ?? 0.0;
            $userConfirmed = $identityResult['user_confirmed'] ?? false;
            $withoutConfirmation = (bool) ($task->request['without_confirmation'] ?? false);

            if ($finalConfidence < 0.97 && ! $userConfirmed && ! $withoutConfirmation) {
                $task->addActivity('decision_point_confidence_check', "Условие: confidence < 0.97 И НЕ user_confirmed И НЕ without_confirmation (текущий: {$finalConfidence}, подтверждено: нет, без подтверждения: нет). Решение: PAUSE - ожидание подтверждения пользователя", [
                    'condition' => 'confidence < 0.97 AND NOT user_confirmed AND NOT without_confirmation',
                    'confidence' => $finalConfidence,
                    'user_confirmed' => false,
                    'without_confirmation' => false,
                    'decision' => 'pause_pending_confirmation',
                ]);

                $task->status = 'pending_confirmation';
                $task->save();

                $exam->research_status = 'queued';
                $exam->save();

                \Illuminate\Support\Facades\Log::warning('Research pipeline paused: confidence too low', [
                    'exam_id' => $exam->id,
                    'task_id' => $task->id,
                    'confidence' => $finalConfidence,
                    'required' => 0.97,
                    'identity' => $identityResult,
                ]);

                $task->addActivity('confidence_too_low', "Pipeline paused: confidence too low ({$finalConfidence} < 0.97)", [
                    'confidence' => $finalConfidence,
                    'required' => 0.97,
                ]);

                // STOP - do not continue pipeline with low confidence
                return;
            }

            // If user confirmed or without_confirmation, log it
            if ($userConfirmed || $withoutConfirmation) {
                $task->addActivity('decision_point_confidence_check', "Условие: confidence >= 0.97 ИЛИ user_confirmed ИЛИ without_confirmation (текущий: {$finalConfidence}, подтверждено: ".($userConfirmed?'да':'нет').", без подтверждения: ".($withoutConfirmation?'да':'нет').'). Решение: продолжить к проверке hold', [
                    'condition' => 'confidence >= 0.97 OR user_confirmed OR without_confirmation',
                    'confidence' => $finalConfidence,
                    'user_confirmed' => $userConfirmed,
                    'without_confirmation' => $withoutConfirmation,
                    'decision' => 'continue_to_hold_check',
                ]);

                \Illuminate\Support\Facades\Log::info('Confidence check passed: user confirmed identity', [
                    'exam_id' => $exam->id,
                    'task_id' => $task->id,
                    'original_confidence' => $identityResult['original_confidence'] ?? null,
                    'boosted_confidence' => $finalConfidence,
                ]);
            } else {
                $task->addActivity('decision_point_confidence_check', "Условие: confidence >= 0.97 (текущий: {$finalConfidence}). Решение: продолжить к проверке hold", [
                    'condition' => 'confidence >= 0.97',
                    'confidence' => $finalConfidence,
                    'user_confirmed' => false,
                    'decision' => 'continue_to_hold_check',
                ]);
            }
        }

        // Check if we have a hold - if yes, STOP here and wait for confirmation
        // UNLESS without_confirmation is true
        $withoutConfirmation = (bool) ($task->request['without_confirmation'] ?? false);

        if (($identityResult['hold'] ?? false) === true) {
            if ($withoutConfirmation) {
                // Skip hold - auto-confirm with AI reasoning
                $task->addActivity('decision_point_hold_check', 'Условие: hold = true И without_confirmation = true. Решение: автоподтверждение, продолжить пайплайн', [
                    'condition' => 'hold = true AND without_confirmation = true',
                    'hold' => true,
                    'without_confirmation' => true,
                    'decision' => 'auto_confirm_continue',
                ]);

                \Illuminate\Support\Facades\Log::info('Skipping hold due to without_confirmation=true', [
                    'exam_id' => $exam->id,
                    'task_id' => $task->id,
                ]);

                $task->addActivity('hold_auto_confirmed', 'Identity hold auto-confirmed (without_confirmation mode)');

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
                $task->addActivity('decision_point_hold_check', 'Условие: hold = true И without_confirmation = false. Решение: PAUSE - ожидание подтверждения пользователя', [
                    'condition' => 'hold = true AND without_confirmation = false',
                    'hold' => true,
                    'without_confirmation' => false,
                    'decision' => 'pause_pending_confirmation',
                ]);

                $task->status = 'pending_confirmation';
                $task->save();

                $exam->research_status = 'queued';
                $exam->save();

                \Illuminate\Support\Facades\Log::info('Research pipeline paused for identity confirmation', [
                    'exam_id' => $exam->id,
                    'task_id' => $task->id,
                    'identity' => $identityResult,
                ]);

                $task->addActivity('identity_hold', 'Pipeline paused: waiting for user confirmation');

                // STOP - do not continue pipeline until user confirms
                return;
            }
        }

        // If identity is uncertain with low confidence, run variant_probe
        if (
            ($identityResult['status'] ?? '') === 'uncertain'
            || ($identityResult['confidence'] ?? 1.0) < 0.80
        ) {
            $currentStatus = $identityResult['status'] ?? 'unknown';
            $currentConfidence = $identityResult['confidence'] ?? 1.0;
            $task->addActivity('decision_point_variant_probe', "Условие: status = 'uncertain' ИЛИ confidence < 0.80 (текущий: status={$currentStatus}, confidence={$currentConfidence}). Решение: запустить Variant Probe для уточнения варианта", [
                'condition' => "status = 'uncertain' OR confidence < 0.80",
                'status' => $currentStatus,
                'confidence' => $currentConfidence,
                'decision' => 'run_variant_probe',
            ]);

            $task->addActivity('variant_probe_started', 'Running variant probe to disambiguate exam');

            $variantResult = $svc->runVariantProbe($exam, $task, $identityResult);

            // If disambiguation is needed, stop and wait for user input
            // UNLESS without_confirmation is true
            if ($variantResult['disambiguation_needed'] ?? false) {
                if ($withoutConfirmation) {
                    // Run AI auto-clarification instead of waiting for user
                    $task->addActivity('decision_point_disambiguation', 'Условие: disambiguation_needed = true И without_confirmation = true. Решение: запустить AutoClarification вместо ожидания пользователя', [
                        'condition' => 'disambiguation_needed = true AND without_confirmation = true',
                        'disambiguation_needed' => true,
                        'without_confirmation' => true,
                        'decision' => 'run_auto_clarification',
                    ]);

                    \Illuminate\Support\Facades\Log::info('Running AI auto-clarification due to without_confirmation=true', [
                        'exam_id' => $exam->id,
                        'task_id' => $task->id,
                    ]);

                    $task->addActivity('auto_clarification_started', 'Running AI auto-clarification');

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
                    $task->addActivity('decision_point_disambiguation', 'Условие: disambiguation_needed = true И without_confirmation = false. Решение: PAUSE - ожидание уточнения от пользователя', [
                        'condition' => 'disambiguation_needed = true AND without_confirmation = false',
                        'disambiguation_needed' => true,
                        'without_confirmation' => false,
                        'decision' => 'pause_pending_clarification',
                    ]);

                    $task->status = 'pending_clarification';
                    $task->save();

                    $exam->research_status = 'queued';
                    $exam->save();

                    \Illuminate\Support\Facades\Log::info('Research pipeline paused for variant clarification', [
                        'exam_id' => $exam->id,
                        'task_id' => $task->id,
                        'variant_result' => $variantResult,
                    ]);

                    $task->addActivity('pending_clarification', 'Pipeline paused: waiting for user clarification');

                    return;
                }
            }
        }

        // Identity confirmed or certain enough - proceed with main pipeline
        $task->status = 'running';
        $task->save();

        $exam->research_status = 'running_overview'; // Use existing enum value
        $exam->save();

        $task->addActivity('pipeline_started', 'Starting main research pipeline (overview + structure)');

        // Save identity snapshot before running structure pipeline
        $identitySnapshot = $task->result['identity'] ?? null;

        // Run the main structure building pipeline
        $task->updateHeartbeat(); // Heartbeat before overview pipeline
        $pipelineResult = $svc->runPipeline($exam, $task);
        $task->updateHeartbeat(); // Heartbeat after overview pipeline

        // Check if pipeline failed
        if (isset($pipelineResult['ok']) && $pipelineResult['ok'] === false) {
            $error = $pipelineResult['error'] ?? 'unknown_error';
            $errors = $pipelineResult['errors'] ?? [];
            $errorMessage = implode('; ', $errors);

            $task->addActivity('pipeline_failed', "Pipeline failed: {$error}", [
                'error' => $error,
                'errors' => $errors,
            ]);

            $task->status = 'failed';
            $task->error = $errorMessage ?: "Pipeline failed: {$error}";
            $task->save();

            $exam->research_status = 'failed';
            $exam->save();

            \Illuminate\Support\Facades\Log::error('Research pipeline failed', [
                'exam_id' => $exam->id,
                'task_id' => $task->id,
                'error' => $error,
                'errors' => $errors,
            ]);

            return;
        }

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
            $complianceScore = $sanityResult['compliance_score'] ?? 0;
            if ($complianceScore < 0.85) {
                $task->addActivity('decision_point_sanity_check', "Условие: compliance_score < 0.85 (текущий: {$complianceScore}). Решение: добавить warning и продолжить к ЭТАПУ 4 (Examples)", [
                    'condition' => 'compliance_score < 0.85',
                    'compliance_score' => $complianceScore,
                    'decision' => 'add_warning_continue',
                ]);

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
            } else {
                $task->addActivity('decision_point_sanity_check', "Условие: compliance_score >= 0.85 (текущий: {$complianceScore}). Решение: продолжить к ЭТАПУ 4 (Examples)", [
                    'condition' => 'compliance_score >= 0.85',
                    'compliance_score' => $complianceScore,
                    'decision' => 'continue_to_examples',
                ]);
            }
        }

        // Generate example questions for each archetype
        try {
            \Illuminate\Support\Facades\Log::info('Starting example generation', [
                'exam_id' => $exam->id,
                'task_id' => $task->id,
            ]);

            $task->addActivity('example_generation_started', 'Generating example questions for archetypes');

            $exampleResult = $svc->generateExamples($exam, $task, 1); // 1 example per archetype

            \Illuminate\Support\Facades\Log::info('Example generation completed', [
                'exam_id' => $exam->id,
                'task_id' => $task->id,
                'examples_created' => $exampleResult['examples_created'] ?? 0,
            ]);

            $examplesCount = $exampleResult['examples_created'] ?? 0;
            $task->addActivity('example_generation_completed', "Generated {$examplesCount} example questions", [
                'examples_count' => $examplesCount,
            ]);

            // Update task result with example generation info
            $result = (array) ($task->result ?? []);
            $result['examples'] = $exampleResult;
            $task->result = $result;
            $task->save();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Example generation failed', [
                'exam_id' => $exam->id,
                'task_id' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $task->addActivity('example_generation_failed', 'Example generation failed: '.$e->getMessage());

            // Don't fail the whole job - examples are nice-to-have
            $result = (array) ($task->result ?? []);
            $result['examples_error'] = $e->getMessage();
            $task->result = $result;
            $task->save();
        }
    }
}
