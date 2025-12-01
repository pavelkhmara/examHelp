<?php

namespace App\Jobs;

use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\AssemblyResolver;
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

    public function handle(ExamResearchService $svc, AssemblyResolver $assemblyResolver): void
    {
        $task = GenerationTask::query()->findOrFail($this->taskId);
        /** @var Exam $exam */
        $exam = Exam::query()->findOrFail($task->exam_id);

        // CRITICAL: Prevent duplicate execution when Job is retried or dispatched multiple times
        // Stop if task is already processing or finished
        if (in_array($task->status, ['running', 'completed', 'pending_confirmation', 'pending_clarification'], true)) {
            \Illuminate\Support\Facades\Log::info('Job stopped: task already processing or finished', [
                'task_id' => $task->id,
                'status' => $task->status,
                'attempt' => $this->attempts(),
            ]);

            $task->addActivity('job_stopped_duplicate', 'Job execution prevented - task already processing or finished', [
                'status' => $task->status,
                'attempt' => $this->attempts(),
            ]);

            return;
        }

        // Set status to running immediately so Activity Timeline appears
        $task->status = 'running';
        $task->save();

        $exam->research_status = 'running_overview';
        $exam->save();

        // Add activity AFTER duplicate check
        $task->addActivity('job_started', 'Research job started');
        $task->updateHeartbeat(); // Initial heartbeat

        // Phase 9: Check if we have a valid ConfirmedIdentity that can be reused
        /** @var \App\Services\LanguageApp\ConfirmedIdentityService $confirmedIdentityService */
        $confirmedIdentityService = app(\App\Services\LanguageApp\ConfirmedIdentityService::class);
        $rerunCheck = $confirmedIdentityService->shouldRerunIdentity($exam);

        // Check if identity stage was already run
        $identityResult = $task->result['identity'] ?? null;

        // If we have a valid ConfirmedIdentity and no rerun is needed, reuse it
        if (! $rerunCheck['should_rerun'] && $rerunCheck['confirmed_identity']) {
            $confirmedIdentity = $rerunCheck['confirmed_identity'];
            $identityResult = $confirmedIdentity->identity_data;

            // Mark as reused from ConfirmedIdentity
            $identityResult['confirmed_identity_id'] = $confirmedIdentity->id;
            $identityResult['confirmed_at'] = $confirmedIdentity->confirmed_at->toISOString();
            $identityResult['reused_from_confirmed'] = true;

            // Save to task result
            $result = (array) ($task->result ?? []);
            $result['identity'] = $identityResult;
            $task->result = $result;
            $task->save();

            \Illuminate\Support\Facades\Log::info('Reusing confirmed identity from database', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
                'confirmed_identity_id' => $confirmedIdentity->id,
            ]);

            $task->addActivity('confirmed_identity_reused', 'Using previously confirmed identity (no field changes detected)', [
                'confirmed_identity_id' => $confirmedIdentity->id,
                'confirmed_at' => $confirmedIdentity->confirmed_at->format('d.m.Y H:i:s'),
            ]);
        } elseif ($rerunCheck['should_rerun'] && $rerunCheck['confirmed_identity']) {
            // Fields changed - warn about rerun
            $changedFields = $rerunCheck['changed_fields'] ?? [];
            \Illuminate\Support\Facades\Log::warning('Identity-affecting fields changed, re-running identity verification', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
                'changed_fields' => $changedFields,
            ]);

            $task->addActivity('identity_fields_changed_warning', 'Identity-affecting fields changed since last confirmation - re-running verification', [
                'changed_fields' => $changedFields,
                'reason' => $rerunCheck['reason'] ?? 'Fields changed',
            ]);
        }

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
            // IMPORTANT: Save clarification flags before running AI (they will be lost otherwise)
            $savedClarificationFlags = [];
            if ($identityResult) {
                $savedClarificationFlags = [
                    'user_provided_clarification' => $identityResult['user_provided_clarification'] ?? false,
                    'clarification_provided_at' => $identityResult['clarification_provided_at'] ?? null,
                    'clarification_data' => $identityResult['clarification_data'] ?? null,
                    'clarification_notes' => $identityResult['clarification_notes'] ?? null,
                ];
            }

            // Check if we should use iterative verification (default: true)
            $useIterativeVerification = $task->request['use_iterative_verification'] ?? true;

            if ($useIterativeVerification) {
                // S1: Run iterative identity verification (new method with web search)
                $task->addActivity('iterative_verification_started', 'Running iterative identity verification (max 3 attempts, 6 min timeout)');
                $task->updateHeartbeat(); // Heartbeat before long operation

                $identityResult = $svc->runIterativeIdentityVerification($exam, $task);
                $task->refresh();
                $task->updateHeartbeat(); // Heartbeat after identity verification

                // IMPORTANT: Restore clarification flags after AI call
                if (! empty($savedClarificationFlags['user_provided_clarification'])) {
                    $identityResult = array_merge($identityResult, $savedClarificationFlags);
                    \Illuminate\Support\Facades\Log::info('Restored clarification flags after AI call', [
                        'task_id' => $task->id,
                        'flags' => $savedClarificationFlags,
                    ]);
                }

                // Check if verification needs clarification (low confidence, needs user input)
                if (($identityResult['status'] ?? '') === 'needs_clarification') {
                    $message = $identityResult['message'] ?? 'Please provide more information about your exam';
                    $followupQuestions = $identityResult['followup_questions'] ?? [];

                    $task->addActivity('iterative_verification_needs_clarification', "Identity verification needs clarification (confidence: {$identityResult['confidence']})", [
                        'confidence' => $identityResult['confidence'] ?? 0,
                        'followup_questions_count' => count($followupQuestions),
                    ]);

                    // Set task to pending_clarification and wait for user
                    $task->status = 'pending_clarification';

                    // Save identity data to task.result so Vue component can display followup questions
                    $result = (array) ($task->result ?? []);
                    $result['identity'] = $identityResult;
                    $task->result = $result;
                    $task->save();

                    $exam->research_status = 'pending_clarification';
                    $exam->save();

                    \Illuminate\Support\Facades\Log::info('Iterative identity verification needs user clarification', [
                        'exam_id' => $exam->id,
                        'task_id' => $task->id,
                        'confidence' => $identityResult['confidence'] ?? 0,
                        'followup_questions_count' => count($followupQuestions),
                    ]);

                    // STOP - wait for user to provide clarification
                    return;
                }

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

                // IMPORTANT: Restore clarification flags after AI call (same as iterative method)
                if (! empty($savedClarificationFlags['user_provided_clarification'])) {
                    $identityResult = array_merge($identityResult, $savedClarificationFlags);
                    \Illuminate\Support\Facades\Log::info('Restored clarification flags after AI call (legacy method)', [
                        'task_id' => $task->id,
                        'flags' => $savedClarificationFlags,
                    ]);
                }
            }

            $confidence = $identityResult['confidence'] ?? 0.0;
            $task->addActivity('identity_guard_completed', "Identity verified with confidence: {$confidence}", [
                'confidence' => $confidence,
                'status' => $identityResult['status'] ?? 'unknown',
            ]);

            // Update exam->identity with verified identity data so QuickCheck can see it
            $exam->identity = $identityResult;
            $exam->save();

            // NOTE: ConfidenceBoost is now a separate manual action (ConfidenceBoostAction)
            // It is no longer part of the automatic pipeline
            // Operator can trigger it manually from Nova when confidence is 0.80-0.80

            // CRITICAL: After identity guard, check if confidence is acceptable
            // If confidence < 0.8, we MUST stop and request user confirmation
            // UNLESS user already confirmed (from ConfirmIdentityAction) OR without_confirmation=true
            $finalConfidence = $identityResult['confidence'] ?? 0.0;
            $userConfirmed = $identityResult['user_confirmed'] ?? false;
            $withoutConfirmation = (bool) ($task->request['without_confirmation'] ?? false);

            if ($finalConfidence < 0.8 && ! $userConfirmed && ! $withoutConfirmation) {
                $task->addActivity('decision_point_confidence_check', "Условие: confidence < 0.8 И НЕ user_confirmed И НЕ without_confirmation (текущий: {$finalConfidence}, подтверждено: нет, без подтверждения: нет). Решение: PAUSE - ожидание подтверждения пользователя", [
                    'condition' => 'confidence < 0.8 AND NOT user_confirmed AND NOT without_confirmation',
                    'confidence' => $finalConfidence,
                    'user_confirmed' => false,
                    'without_confirmation' => false,
                    'decision' => 'pause_pending_confirmation',
                ]);

                // CRITICAL: Save identityResult to task->result['identity'] so CardManager can access it
                $result = (array) ($task->result ?? []);
                $result['identity'] = $identityResult;
                $task->result = $result;

                $task->status = 'pending_confirmation';
                $task->save();

                $exam->research_status = 'queued';
                $exam->save();

                \Illuminate\Support\Facades\Log::warning('Research pipeline paused: confidence too low', [
                    'exam_id' => $exam->id,
                    'task_id' => $task->id,
                    'confidence' => $finalConfidence,
                    'required' => 0.8,
                    'identity' => $identityResult,
                ]);

                $task->addActivity('confidence_too_low', "Pipeline paused: confidence too low ({$finalConfidence} < 0.8)", [
                    'confidence' => $finalConfidence,
                    'required' => 0.8,
                ]);

                // STOP - do not continue pipeline with low confidence
                return;
            }

            // If user confirmed or without_confirmation, log it
            if ($userConfirmed || $withoutConfirmation) {
                $task->addActivity('decision_point_confidence_check', "Условие: confidence >= 0.8 ИЛИ user_confirmed ИЛИ without_confirmation (текущий: {$finalConfidence}, подтверждено: ".($userConfirmed ? 'да' : 'нет').', без подтверждения: '.($withoutConfirmation ? 'да' : 'нет').'). Решение: продолжить к проверке hold', [
                    'condition' => 'confidence >= 0.8 OR user_confirmed OR without_confirmation',
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
                $task->addActivity('decision_point_confidence_check', "Условие: confidence >= 0.8 (текущий: {$finalConfidence}). Решение: продолжить к проверке hold", [
                    'condition' => 'confidence >= 0.8',
                    'confidence' => $finalConfidence,
                    'user_confirmed' => false,
                    'decision' => 'continue_to_hold_check',
                ]);
            }

            // Phase 9: Create ConfirmedIdentity when confidence is high enough or user confirmed
            // Only create if we don't already have one for this identity AND identity is not reused
            $isReused = $identityResult['reused_from_confirmed'] ?? false;
            if (! $isReused && ($finalConfidence >= 0.8 || $userConfirmed)) {
                try {
                    $existingConfirmed = $confirmedIdentityService->shouldRerunIdentity($exam);
                    // Only create if there's no existing valid ConfirmedIdentity
                    if ($existingConfirmed['should_rerun'] || ! $existingConfirmed['confirmed_identity']) {
                        $confirmedIdentity = $confirmedIdentityService->createConfirmedIdentity(
                            $exam,
                            $identityResult,
                            $task
                        );

                        $task->addActivity('confirmed_identity_created', 'Identity confirmed and saved to database', [
                            'confirmed_identity_id' => $confirmedIdentity->id,
                            'confidence' => $finalConfidence,
                            'user_confirmed' => $userConfirmed,
                        ]);

                        \Illuminate\Support\Facades\Log::info('ConfirmedIdentity created', [
                            'exam_id' => $exam->id,
                            'task_id' => $task->id,
                            'confirmed_identity_id' => $confirmedIdentity->id,
                        ]);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Failed to create ConfirmedIdentity', [
                        'exam_id' => $exam->id,
                        'task_id' => $task->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Don't fail the job - this is non-critical
                }
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

                // Save identity data to task.result so Vue component can display it
                $result = (array) ($task->result ?? []);
                $result['identity'] = $identityResult;
                $task->result = $result;
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
            $task->updateHeartbeat(); // Heartbeat before variant probe

            try {
                $variantResult = $svc->runVariantProbe($exam, $task, $identityResult);
                $task->updateHeartbeat(); // Heartbeat after variant probe

                $task->addActivity('variant_probe_completed', 'Variant probe completed', [
                    'disambiguation_needed' => $variantResult['disambiguation_needed'] ?? false,
                    'candidates_count' => count($variantResult['candidates'] ?? []),
                ]);
            } catch (\Throwable $e) {
                $task->addActivity('variant_probe_failed', 'Variant probe failed: '.$e->getMessage());

                \Illuminate\Support\Facades\Log::error('Variant probe failed', [
                    'exam_id' => $exam->id,
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $task->status = 'failed';
                $task->error = 'Variant probe failed: '.$e->getMessage();
                $task->save();

                $exam->research_status = 'failed';
                $exam->save();

                return;
            }

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
                    $task->updateHeartbeat(); // Heartbeat before AI call

                    try {
                        $autoClarification = $svc->runAutoClarification($exam, $task, $identityResult);

                        $task->updateHeartbeat(); // Heartbeat after AI call
                        $task->addActivity('auto_clarification_completed', 'Auto-clarification completed successfully', [
                            'confidence' => $autoClarification['confidence'] ?? null,
                            'inferred_fields' => array_keys($autoClarification['inferred_data'] ?? []),
                        ]);

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
                        $task->addActivity('identity_guard_rerun', 'Re-running identity guard with auto-clarified data');
                        $task->updateHeartbeat(); // Heartbeat before re-run

                        $identityResult = $svc->runIdentityGuard($exam, $task);
                        $task->refresh();
                        $task->updateHeartbeat(); // Heartbeat after re-run

                        // Continue with pipeline
                    } catch (\Throwable $e) {
                        $task->addActivity('auto_clarification_failed', 'Auto-clarification failed: '.$e->getMessage());

                        \Illuminate\Support\Facades\Log::error('Auto-clarification failed', [
                            'exam_id' => $exam->id,
                            'task_id' => $task->id,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);

                        $task->status = 'failed';
                        $task->error = 'Auto-clarification failed: '.$e->getMessage();
                        $task->save();

                        $exam->research_status = 'failed';
                        $exam->save();

                        return;
                    }
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

        try {
            // Check if two-phase generation is enabled (v2 architecture)
            $useTwoPhaseGeneration = $task->request['use_two_phase_generation'] ?? true; // Default to v2

            // Check if parallel section generation is enabled (v2_parallel)
            $useParallelSections = $task->request['use_parallel_sections'] ?? true; // Default to parallel

            if ($useTwoPhaseGeneration) {
                // V2 ARCHITECTURE: Two-phase generation (Phase A + Phase B)
                $task->addActivity('two_phase_mode_enabled', 'Using v2 two-phase generation (Phase A: skeleton, Phase B: assembly plan)');

                // Phase A: Generate skeleton structure
                $exam->research_status = 'running_phase_a';
                $exam->save();
                $task->updateHeartbeat(); // Heartbeat before Phase A
                $phaseASkeleton = $svc->runPhaseA($exam, $task);
                $task->updateHeartbeat(); // Heartbeat after Phase A

                // Phase B: Choose between parallel or sequential generation
                if ($useParallelSections) {
                    // PARALLEL MODE: Dispatch separate jobs for each section
                    $task->addActivity('parallel_mode_enabled', 'Using parallel section generation (v2_parallel)');

                    $sections = $phaseASkeleton['sections'] ?? [];
                    $sectionIds = array_column($sections, 'id');

                    // Initialize section tracking in task.request
                    $request = (array) ($task->request ?? []);
                    $request['expected_sections'] = $sectionIds;
                    $request['sections_status'] = array_fill_keys($sectionIds, 'pending');
                    $task->request = $request;
                    $task->save();

                    $task->addActivity('dispatching_sections', 'Dispatching '.count($sections).' section assembly jobs', [
                        'sections' => $sectionIds,
                        'mode' => 'parallel',
                    ]);

                    // Dispatch GenerateSectionAssemblyJob for each section
                    foreach ($sections as $section) {
                        GenerateSectionAssemblyJob::dispatch($task->id, $section['id'], $section);
                    }

                    // Dispatch FinalizeSectionGenerationJob to wait and finalize
                    FinalizeSectionGenerationJob::dispatch($task->id)->delay(now()->addSeconds(30));

                    $task->addActivity('parallel_jobs_dispatched', 'Section assembly jobs dispatched, waiting for completion');

                    \Illuminate\Support\Facades\Log::info('Parallel section generation dispatched', [
                        'exam_id' => $exam->id,
                        'task_id' => $task->id,
                        'sections_count' => count($sections),
                    ]);

                    // STOP HERE - wait for FinalizeSectionGenerationJob to complete
                    return;
                } else {
                    // SEQUENTIAL MODE: Generate all sections in one AI call (original Phase B)
                    $task->addActivity('sequential_mode_enabled', 'Using sequential section generation (v2_sequential)');

                    $exam->research_status = 'running_phase_b';
                    $exam->save();
                    $task->updateHeartbeat(); // Heartbeat before Phase B
                    $phaseBStructure = $svc->runPhaseB($exam, $task, $phaseASkeleton);
                    $task->updateHeartbeat(); // Heartbeat after Phase B

                    // Build compatible pipelineResult structure for downstream code
                    $pipelineResult = [
                        'ok' => true,
                        'overview' => $phaseBStructure, // Complete structure with assembly
                        'structure' => $phaseBStructure,
                        'phase_a_skeleton' => $phaseASkeleton,
                        'phase_b_complete' => $phaseBStructure,
                        'generation_mode' => 'two_phase_v2_sequential',
                    ];
                }
            } else {
                // V1 ARCHITECTURE: Single-phase generation (legacy)
                $task->addActivity('legacy_mode_enabled', 'Using legacy single-phase generation (v1)');

                $pipelineResult = $svc->runPipeline($exam, $task);
                $task->updateHeartbeat(); // Heartbeat after overview pipeline
            }

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
        } catch (\Throwable $e) {
            $task->addActivity('pipeline_exception', 'Pipeline threw exception: '.$e->getMessage());

            \Illuminate\Support\Facades\Log::error('Research pipeline threw exception', [
                'exam_id' => $exam->id,
                'task_id' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $task->status = 'failed';
            $task->error = 'Pipeline exception: '.$e->getMessage();
            $task->save();

            $exam->research_status = 'failed';
            $exam->save();

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
                $task->addActivity('decision_point_sanity_check', "Условие: compliance_score < 0.85 (текущий: {$complianceScore}). Решение: добавить warning и продолжить к генерации примеров", [
                    'condition' => 'compliance_score < 0.85',
                    'compliance_score' => $complianceScore,
                    'decision' => 'add_warning_continue',
                    'architecture' => $useTwoPhaseGeneration ? 'v2' : 'v1',
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
                $task->addActivity('decision_point_sanity_check', "Условие: compliance_score >= 0.85 (текущий: {$complianceScore}). Решение: продолжить к генерации примеров", [
                    'condition' => 'compliance_score >= 0.85',
                    'compliance_score' => $complianceScore,
                    'decision' => 'continue_to_examples',
                    'architecture' => $useTwoPhaseGeneration ? 'v2' : 'v1',
                ]);
            }
        }

        // Generate example questions for each archetype (both V1 and V2)
        // Skip if skip_examples flag is set (default: false for backward compatibility)
        $skipExamples = (bool) ($task->request['skip_examples'] ?? false);

        if ($skipExamples) {
            $task->addActivity('example_generation_skipped', 'Example generation skipped (skip_examples=true)');
            \Illuminate\Support\Facades\Log::info('Skipping example generation', [
                'exam_id' => $exam->id,
                'task_id' => $task->id,
                'reason' => 'skip_examples flag set',
            ]);
        } else {
            try {
                \Illuminate\Support\Facades\Log::info('Starting example generation', [
                    'exam_id' => $exam->id,
                    'task_id' => $task->id,
                    'architecture' => $useTwoPhaseGeneration ? 'v2' : 'v1',
                ]);

                $task->addActivity('example_generation_started', 'Generating example questions for archetypes');
                $task->updateHeartbeat(); // Heartbeat before example generation

                $exampleResult = $svc->generateExamples($exam, $task, 1); // 1 example per archetype

                $task->updateHeartbeat(); // Heartbeat after example generation

                \Illuminate\Support\Facades\Log::info('Example generation completed', [
                    'exam_id' => $exam->id,
                    'task_id' => $task->id,
                    'examples_created' => $exampleResult['examples_created'] ?? 0,
                    'architecture' => $useTwoPhaseGeneration ? 'v2' : 'v1',
                ]);

                $examplesCount = $exampleResult['examples_created'] ?? 0;
                $task->addActivity('example_generation_completed', "Generated {$examplesCount} example questions", [
                    'examples_count' => $examplesCount,
                    'architecture' => $useTwoPhaseGeneration ? 'v2' : 'v1',
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
                    'architecture' => $useTwoPhaseGeneration ? 'v2' : 'v1',
                ]);

                $task->addActivity('example_generation_failed', 'Example generation failed: '.$e->getMessage());

                // Don't fail the whole job - examples are nice-to-have
                $result = (array) ($task->result ?? []);
                $result['examples_error'] = $e->getMessage();
                $task->result = $result;
                $task->save();
            }
        } // end else (skip_examples)

        // ========================================
        // FINALIZATION: Complete research task
        // ========================================

        $task->addActivity('finalization_started', 'Starting research finalization');

        // Materialize structure_v2 into database tables (v2 only)
        // IMPORTANT: Must be done BEFORE resolving plans (plans need ExamCategory to exist)
        if ($useTwoPhaseGeneration && ! empty($exam->meta['structure_v2']['sections'])) {
            $task->addActivity('materializing_structure', 'Materializing structure_v2 into database tables');

            $materializer = new \App\Services\LanguageApp\StructureMaterializer;
            $stats = $materializer->materialize($exam, $exam->meta['structure_v2']['sections']);

            $task->addActivity('structure_materialized', sprintf(
                'Materialized: %d categories, %d question groups, %d questions',
                $stats['categories'],
                $stats['question_groups'],
                $stats['questions']
            ));
        }

        // Resolve generation plans from assembly configuration (v2 only)
        // IMPORTANT: Must be done AFTER materializing (needs ExamCategory records)
        if ($useTwoPhaseGeneration && ! empty($exam->meta['structure_v2']['sections'])) {
            $task->addActivity('resolve_plans_started', 'Resolving generation plans from assembly configuration');
            $task->updateHeartbeat();

            try {
                $plans = $assemblyResolver->resolve($exam);
                $plansCount = count($plans);

                $task->addActivity('resolve_plans_completed', "Resolved {$plansCount} generation plans", [
                    'plans_count' => $plansCount,
                    'plans' => collect($plans)->map(fn ($p) => [
                        'id' => $p->id,
                        'section_id' => $p->section_id,
                        'assembly_mode' => $p->assembly_mode,
                        'total_questions' => $p->total_questions,
                    ])->toArray(),
                ]);

                \Illuminate\Support\Facades\Log::info('[RunExamResearchJob] Generation plans resolved', [
                    'exam_id' => $exam->id,
                    'task_id' => $task->id,
                    'plans_count' => $plansCount,
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('[RunExamResearchJob] Plan resolution failed', [
                    'exam_id' => $exam->id,
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);

                $task->addActivity('resolve_plans_failed', 'Plan resolution failed: '.$e->getMessage());

                // Don't fail the whole job - plans can be resolved on-demand later
                $result = (array) ($task->result ?? []);
                $result['plan_resolution_error'] = $e->getMessage();
                $task->result = $result;
                $task->save();
            }
        }

        // Update exam research_status
        $exam->research_status = 'completed';
        $exam->save();

        $task->addActivity('research_completed', 'Research pipeline completed successfully');

        // Finalize task
        $task->status = 'completed';
        $task->result = [
            'success' => true,
            'architecture' => $useTwoPhaseGeneration ? 'v2' : 'v1',
            'structure_v2' => $useTwoPhaseGeneration ? $exam->meta['structure_v2'] : null,
        ];
        $task->save();

        \Illuminate\Support\Facades\Log::info('✅ Research job completed successfully', [
            'exam_id' => $exam->id,
            'task_id' => $task->id,
            'architecture' => $useTwoPhaseGeneration ? 'v2' : 'v1',
            'categories_count' => \App\Models\ExamCategory::where('exam_id', $exam->id)->count(),
        ]);
    }
}
