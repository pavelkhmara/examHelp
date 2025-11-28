<?php

namespace App\Jobs;

use App\Models\Exam;
use App\Models\GenerationPlan;
use App\Models\GenerationTask;
use App\Services\LanguageApp\Contracts\QuestionGroupContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SynthesizeQuestionsJob implements ShouldQueue
{
    use \App\Jobs\Concerns\EnsuresConnectionStability;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800; // 30 minutes for AI-heavy task

    public function __construct(public int $taskId) {}

    public function handle(): void
    {
        // Ensure database connection is stable before starting
        $this->ensureConnection();

        $task = GenerationTask::query()->findOrFail($this->taskId);
        /** @var Exam $exam */
        $exam = Exam::query()->findOrFail($task->exam_id);

        // Prevent duplicate execution
        if ($task->status === 'completed') {
            Log::info('Job stopped: task already completed', [
                'task_id' => $task->id,
                'status' => $task->status,
            ]);

            return;
        }

        try {
            // Set status to running
            $task->status = 'running';
            $task->save();

            $task->addActivity('synthesize_started', 'Starting question synthesis for all sections');
            $task->updateHeartbeat();

            // Clean up stale generated_questions_v2 data
            // This prevents accumulation of questions with outdated section_ids
            $this->cleanupGeneratedQuestions($exam, $task);

            Log::info('Synthesize questions job started', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
                'plan_ids_requested' => $task->request['plan_ids'] ?? null,
            ]);

            // Load generation plans - support specific plan_ids or all pending/failed
            // Use atomic UPDATE to prevent race conditions (optimistic locking)
            if (isset($task->request['plan_ids']) && is_array($task->request['plan_ids'])) {
                // Specific plans requested - atomic CAS (Compare-And-Swap) update
                $planIds = $task->request['plan_ids'];

                Log::info('Atomically claiming specific plans', [
                    'plan_ids' => $planIds,
                ]);

                // Atomic update - only claim plans that are NOT already in_progress
                // Uses optimistic locking: if another worker already claimed, we skip
                $updated = GenerationPlan::whereIn('id', $planIds)
                    ->whereNotIn('status', ['in_progress', 'completed'])
                    ->update([
                        'status' => 'in_progress',
                        'started_at' => DB::raw('COALESCE(started_at, NOW())'),
                    ]);

                // Load claimed plans
                $plans = GenerationPlan::whereIn('id', $planIds)->get();

                Log::info('Loaded specific plans', [
                    'requested' => count($planIds),
                    'updated' => $updated,
                    'found' => $plans->count(),
                ]);

                if ($plans->isEmpty()) {
                    throw new \Exception('Requested generation plans not found: '.implode(', ', $planIds));
                }
            } else {
                // Load all pending/failed plans for this exam
                Log::info('Atomically claiming pending/failed plans', [
                    'exam_id' => $exam->id,
                ]);

                // Atomic update - claim all pending/failed plans
                // For plans without started_at, set it manually before update
                GenerationPlan::where('exam_id', $exam->id)
                    ->whereIn('status', ['pending', 'failed'])
                    ->whereNull('started_at')
                    ->update(['started_at' => now()]);

                $updated = GenerationPlan::where('exam_id', $exam->id)
                    ->whereIn('status', ['pending', 'failed'])
                    ->update([
                        'status' => 'in_progress',
                    ]);

                // Load ALL in_progress plans (not just recently claimed)
                // This allows us to track plans claimed by other workers too
                $plans = GenerationPlan::where('exam_id', $exam->id)
                    ->where('status', 'in_progress')
                    ->get();

                Log::info('Loaded in_progress plans', [
                    'updated' => $updated,
                    'found' => $plans->count(),
                ]);

                // If no plans in_progress, check if all completed
                if ($plans->isEmpty()) {
                    $completedCount = GenerationPlan::where('exam_id', $exam->id)
                        ->where('status', 'completed')
                        ->count();
                    $totalCount = GenerationPlan::where('exam_id', $exam->id)->count();

                    if ($completedCount === $totalCount && $totalCount > 0) {
                        // All plans already completed - this is success, not error
                        Log::info('All generation plans already completed', [
                            'exam_id' => $exam->id,
                            'completed_count' => $completedCount,
                        ]);
                        $task->addActivity('all_plans_completed', 'All generation plans already completed', [
                            'completed_count' => $completedCount,
                        ]);
                        $task->result = [
                            'success' => true,
                            'message' => 'All plans were already completed',
                            'plans_completed' => $completedCount,
                        ];
                        $task->status = 'completed';
                        $task->save();

                        return;
                    }

                    throw new \Exception('No generation plans found (pending, failed or in_progress). Total: '.$totalCount.', completed: '.$completedCount);
                }
            }

            $task->addActivity('plans_status_check', 'Generation plans status', [
                'total_plans' => GenerationPlan::where('exam_id', $exam->id)->count(),
                'pending' => GenerationPlan::where('exam_id', $exam->id)->where('status', 'pending')->count(),
                'failed' => GenerationPlan::where('exam_id', $exam->id)->where('status', 'failed')->count(),
                'completed' => GenerationPlan::where('exam_id', $exam->id)->where('status', 'completed')->count(),
                'retry_count' => $plans->where('status', 'failed')->count(),
            ]);

            $task->addActivity('plans_loaded', "Loaded {$plans->count()} generation plans", [
                'plans_count' => $plans->count(),
                'total_questions' => $plans->sum('total_questions'),
            ]);

            // Process all plans (synthesize questions in parallel for sections)
            $task->updateHeartbeat();

            // Keep connection alive before long operation
            $this->keepConnectionAlive();

            $results = $this->synthesizeInParallel($task, $exam, $plans);

            $totalGenerated = collect($results)->sum('generated');
            $totalAttached = collect($results)->sum('attached');

            // Generate audio for question groups (listening sections)
            $audioStats = $this->generateGroupAudio($exam, $task);

            // Update task
            $task->result = [
                'success' => true,
                'plans_processed' => $plans->count(),
                'total_generated' => $totalGenerated,
                'total_attached' => $totalAttached,
                'results' => $results,
                'audio_stats' => $audioStats,
            ];
            $task->status = 'completed';
            $task->save();

            $task->addActivity('synthesize_completed', 'Question synthesis completed successfully', [
                'plans_processed' => $plans->count(),
                'total_generated' => $totalGenerated,
                'total_attached' => $totalAttached,
                'audio_generated' => $audioStats['audio_generated'],
            ]);
            $task->updateHeartbeat();

            Log::info('Synthesize questions job completed', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
                'total_generated' => $totalGenerated,
                'total_attached' => $totalAttached,
            ]);
        } catch (\Throwable $e) {
            Log::error('Synthesize questions job failed', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $task->addActivity('synthesize_failed', 'Question synthesis failed: '.$e->getMessage());
            $task->status = 'failed';
            $task->error = 'Question synthesis failed: '.$e->getMessage();
            $task->save();

            throw $e; // Re-throw for retry mechanism
        }
    }

    /**
     * Synthesize questions in parallel for all sections (plans)
     * Uses ParallelQuestionSynthesizer for parallel AI requests
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\GenerationPlan>  $plans
     */
    private function synthesizeInParallel(
        GenerationTask $task,
        Exam $exam,
        \Illuminate\Support\Collection $plans
    ): array {
        // Check if task-level parallelization is enabled
        $useTaskLevelParallelization = config('ai.use_task_level_parallelization', false);

        if ($useTaskLevelParallelization) {
            Log::info('Using task-level parallelization', [
                'task_id' => $task->id,
                'plans_count' => $plans->count(),
            ]);

            return $this->synthesizeWithTaskLevelParallelization($task, $exam, $plans);
        }

        // LEGACY: Use ParallelQuestionSynthesizer for section-level parallelization
        $parallelSynthesizer = app(\App\Services\LanguageApp\ParallelQuestionSynthesizer::class);

        $task->addActivity('parallel_synthesis_start', 'Starting parallel synthesis for all sections', [
            'sections_count' => $plans->count(),
            'async_enabled' => config('ai.async_enabled', false),
        ]);

        try {
            // Synthesize all sections in parallel (if async enabled)
            $results = $parallelSynthesizer->synthesizeBatch($plans, $exam, $task);

            // Log individual section results
            foreach ($results as $result) {
                $category = \App\Models\ExamCategory::find($result['section_id']);
                $sectionName = $category ? $category->name : "Section {$result['section_id']}";

                if ($result['success']) {
                    $task->addActivity('section_completed', "{$sectionName} completed successfully", [
                        'plan_id' => $result['plan_id'],
                        'questions_generated' => $result['generated'] ?? 0,
                        'questions_attached' => $result['attached'] ?? 0,
                    ]);
                } else {
                    $task->addActivity('section_failed', "{$sectionName} failed: ".($result['error'] ?? 'Unknown error'), [
                        'plan_id' => $result['plan_id'],
                        'error' => $result['error'] ?? 'Unknown error',
                    ]);
                }
            }

            return $results;
        } catch (\Throwable $e) {
            Log::error('Parallel synthesis failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $task->addActivity('parallel_synthesis_failed', 'Parallel synthesis failed: '.$e->getMessage());

            throw $e;
        }
    }

    /**
     * NEW: Synthesize questions using task-level parallelization.
     * Dispatches SynthesizeTaskQuestionsJob for each filter/slot.
     *
     * ✅ PLAN-LEVEL PARALLELIZATION: Dispatches ALL plans upfront, then waits for ALL to complete.
     * This allows multiple plans (sections) to be processed simultaneously by different workers.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\GenerationPlan>  $plans
     */
    private function synthesizeWithTaskLevelParallelization(
        GenerationTask $task,
        Exam $exam,
        \Illuminate\Support\Collection $plans
    ): array {
        $task->addActivity('task_level_synthesis_start', 'Starting task-level + plan-level parallelization', [
            'plans_count' => $plans->count(),
        ]);

        // ✅ STEP 1: Dispatch task-level jobs for ALL plans upfront (plan-level parallelization)
        foreach ($plans as $plan) {
            try {
                $this->dispatchTaskLevelJobs($exam, $plan, $task);
                Log::info("Dispatched filters for plan {$plan->id}", [
                    'plan_id' => $plan->id,
                    'section_id' => $plan->section_id,
                ]);
            } catch (\Throwable $e) {
                Log::error("Failed to dispatch filters for plan {$plan->id}", [
                    'error' => $e->getMessage(),
                ]);
                // Don't fail entire job - mark this plan as failed and continue
                $plan->status = 'failed';
                $plan->error = "Failed to dispatch filters: {$e->getMessage()}";
                $plan->save();
            }
        }

        $task->addActivity('all_plans_dispatched', 'All plans dispatched, waiting for completion', [
            'plans_count' => $plans->count(),
        ]);

        // ✅ STEP 2: Wait for ALL plans to complete (polls all plans concurrently)
        $results = $this->waitForAllPlansCompletion($plans, $task);

        return $results;
    }

    /**
     * Dispatch task-level jobs for all filters/slots in a plan.
     *
     * @throws \RuntimeException
     */
    protected function dispatchTaskLevelJobs(Exam $exam, GenerationPlan $plan, GenerationTask $task): void
    {
        Log::info("Dispatching task-level jobs for plan {$plan->id}");

        // Check if jobs were already dispatched (by another worker)
        // If meta.total_filters is set, jobs were already dispatched - just wait for them
        if (isset($plan->meta['total_filters']) && $plan->meta['total_filters'] > 0) {
            $totalFilters = $plan->meta['total_filters'];
            $completedFilters = $plan->meta['filters_completed'] ?? 0;

            Log::info("Jobs already dispatched for plan {$plan->id}, skipping dispatch", [
                'total_filters' => $totalFilters,
                'completed_filters' => $completedFilters,
            ]);

            $task->addActivity('jobs_already_dispatched', "Jobs already dispatched for plan #{$plan->id}", [
                'plan_id' => $plan->id,
                'total_filters' => $totalFilters,
                'completed_filters' => $completedFilters,
            ]);

            return; // Don't dispatch again, just wait for completion
        }

        // Get plan data
        $planData = $plan->plan_data ?? [];
        $mode = $plan->assembly_mode;

        // Extract filters/slots based on assembly mode
        $filters = [];
        if ($mode === 'pool') {
            $filters = $planData['filters'] ?? [];
        } elseif ($mode === 'blueprint') {
            $filters = $planData['slots'] ?? [];
        } elseif ($mode === 'inline') {
            // Check for question_groups first (new format for listening/reading)
            $questionGroups = $planData['question_groups'] ?? [];
            if (! empty($questionGroups)) {
                // For inline + question_groups: each GROUP is a "filter" (task unit)
                // This preserves group context while allowing parallel processing
                foreach ($questionGroups as $groupIndex => $group) {
                    // ✅ VALIDATE CONTRACT: Ensure group spec meets contract requirements
                    QuestionGroupContract::validateQuestionGroupSpec($group);

                    $groupId = $group['id'] ?? "group_{$groupIndex}";
                    $groupQuestions = $group['questions'] ?? [];

                    $filters[] = [
                        'type' => 'question_group',
                        'group_index' => $groupIndex,
                        'group_id' => $groupId,
                        'group_title' => $group['title'] ?? '',
                        'group_stimulus' => $group['stimulus'] ?? [],
                        'group_instructions' => $group['instructions'] ?? [],
                        'group_playback_settings' => $group['playback_settings'] ?? null,
                        'questions' => $groupQuestions,
                        'pick' => count($groupQuestions),
                    ];
                }

                Log::info('Inline mode with question_groups: '.count($filters).' groups as tasks', [
                    'plan_id' => $plan->id,
                    'groups_count' => count($questionGroups),
                    'total_questions' => array_sum(array_map(fn ($g) => count($g['questions'] ?? []), $questionGroups)),
                ]);
            } else {
                // Fallback to placeholders (legacy format)
                $filters = $planData['placeholders'] ?? [];
            }
        } else {
            throw new \RuntimeException("Unknown assembly mode: {$mode}");
        }

        $totalFilters = count($filters);

        if ($totalFilters === 0) {
            Log::warning("No filters found in plan {$plan->id}, marking as completed");
            $plan->markAsCompleted();

            return;
        }

        // Initialize plan metadata
        $plan->meta = array_merge($plan->meta ?? [], [
            'total_filters' => $totalFilters,
            'filters_completed' => 0,
            'questions_generated' => 0,
            'completed_filters' => [],
            'failed_filters' => [],
        ]);
        $plan->save();

        // Dispatch one job per filter
        foreach ($filters as $index => $filter) {
            $filterKey = "section_{$plan->section_id}_filter_{$index}";

            Log::info("Dispatching SynthesizeTaskQuestionsJob for filter {$filterKey}", [
                'plan_id' => $plan->id,
                'filter_index' => $index,
                'filter' => $filter,
            ]);

            SynthesizeTaskQuestionsJob::dispatch(
                taskId: $task->id,
                examId: $exam->id,
                planId: $plan->id,
                filterKey: $filterKey,
                filterData: $filter
            )->onQueue('default');
        }

        Log::info("Dispatched {$totalFilters} task-level jobs for plan {$plan->id}");
    }

    /**
     * Poll plan.meta until all filters completed.
     *
     * @throws \RuntimeException
     */
    protected function waitForTaskCompletion(GenerationPlan $plan, GenerationTask $task): void
    {
        $maxWaitTime = 30 * 60; // 30 minutes
        $pollInterval = 10; // 10 seconds
        $startTime = time();

        $totalFilters = $plan->meta['total_filters'] ?? 0;

        Log::info("Waiting for {$totalFilters} filters to complete (plan {$plan->id})");

        while (true) {
            sleep($pollInterval);

            // Keep connection alive
            $this->keepConnectionAlive();

            // Refresh plan
            $plan->refresh();

            $completedFilters = $plan->meta['filters_completed'] ?? 0;
            $questionsGenerated = $plan->meta['questions_generated'] ?? 0;

            Log::info("Polling plan {$plan->id}: {$completedFilters}/{$totalFilters} filters completed, {$questionsGenerated} questions generated");

            // Update task heartbeat
            $task->updateHeartbeat();

            // Check completion
            if ($completedFilters >= $totalFilters) {
                Log::info("All filters completed for plan {$plan->id}");

                return;
            }

            // Check for failures
            if ($plan->status === 'failed') {
                throw new \RuntimeException("Plan {$plan->id} marked as failed: {$plan->error}");
            }

            // Timeout check
            if (time() - $startTime > $maxWaitTime) {
                throw new \RuntimeException("Timeout waiting for filters to complete (plan {$plan->id})");
            }
        }
    }

    /**
     * ✅ NEW: Wait for ALL plans to complete (plan-level parallelization).
     * Polls all plans concurrently instead of waiting for each plan sequentially.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\GenerationPlan>  $plans
     *
     * @throws \RuntimeException
     */
    protected function waitForAllPlansCompletion(
        \Illuminate\Support\Collection $plans,
        GenerationTask $task
    ): array {
        $maxWaitTime = 30 * 60; // 30 minutes total
        $pollInterval = 10; // 10 seconds
        $startTime = time();

        $planIds = $plans->pluck('id')->toArray();
        $totalPlans = count($planIds);

        Log::info("Waiting for {$totalPlans} plans to complete (plan-level parallelization)", [
            'plan_ids' => $planIds,
        ]);

        $completedPlans = [];
        $failedPlans = [];

        while (true) {
            sleep($pollInterval);

            // Keep connection alive
            $this->keepConnectionAlive();

            // Refresh ALL plans at once
            $freshPlans = GenerationPlan::whereIn('id', $planIds)->get()->keyBy('id');

            // Check each plan's status
            $stillPending = [];
            foreach ($planIds as $planId) {
                $plan = $freshPlans->get($planId);

                if (! $plan) {
                    Log::error("Plan {$planId} not found during polling");
                    $failedPlans[$planId] = 'Plan not found';

                    continue;
                }

                $totalFilters = $plan->meta['total_filters'] ?? 0;
                $completedFilters = $plan->meta['filters_completed'] ?? 0;

                // Already completed/failed - skip
                if (isset($completedPlans[$planId]) || isset($failedPlans[$planId])) {
                    continue;
                }

                // Check completion
                if ($completedFilters >= $totalFilters && $totalFilters > 0) {
                    Log::info("Plan {$planId} completed ({$completedFilters}/{$totalFilters} filters)");
                    $completedPlans[$planId] = [
                        'plan_id' => $planId,
                        'section_id' => $plan->section_id,
                        'success' => true,
                        'generated' => $plan->meta['questions_generated'] ?? 0,
                        'attached' => $plan->meta['questions_generated'] ?? 0,
                    ];

                    continue;
                }

                // Check for failures
                if ($plan->status === 'failed') {
                    Log::error("Plan {$planId} failed: {$plan->error}");
                    $failedPlans[$planId] = $plan->error ?? 'Unknown error';
                    $completedPlans[$planId] = [
                        'plan_id' => $planId,
                        'section_id' => $plan->section_id,
                        'success' => false,
                        'error' => $plan->error ?? 'Unknown error',
                    ];

                    continue;
                }

                // Still pending
                $stillPending[] = $planId;
            }

            // Log progress
            $completedCount = count($completedPlans);
            $failedCount = count($failedPlans);
            Log::info("Plan-level progress: {$completedCount}/{$totalPlans} completed, {$failedCount} failed, ".count($stillPending).' pending');

            // Update task heartbeat
            $task->updateHeartbeat();

            // All plans done?
            if ($completedCount >= $totalPlans) {
                Log::info('All plans completed!', [
                    'total' => $totalPlans,
                    'succeeded' => $completedCount - $failedCount,
                    'failed' => $failedCount,
                ]);

                $task->addActivity('all_plans_completed', 'All plans completed', [
                    'total_plans' => $totalPlans,
                    'succeeded' => $completedCount - $failedCount,
                    'failed' => $failedCount,
                ]);

                return array_values($completedPlans);
            }

            // Timeout check
            if (time() - $startTime > $maxWaitTime) {
                $pendingPlans = implode(', ', $stillPending);

                throw new \RuntimeException("Timeout waiting for plans to complete. Pending plans: {$pendingPlans}");
            }
        }
    }

    /**
     * Clean up stale generated_questions_v2 data before synthesis.
     *
     * This removes questions with section_ids that no longer exist in current ExamCategories.
     * Prevents accumulation of outdated data when Research is re-run (which recreates categories).
     */
    protected function cleanupGeneratedQuestions(Exam $exam, GenerationTask $task): void
    {
        $meta = $exam->meta ?? [];
        $existingQuestions = $meta['generated_questions_v2'] ?? [];

        if (empty($existingQuestions)) {
            return;
        }

        // Get current category IDs
        $currentCategoryIds = $exam->categories()->pluck('id')->toArray();

        // Filter out questions with stale section_ids
        $validQuestions = array_filter($existingQuestions, function ($q) use ($currentCategoryIds) {
            $sectionId = $q['section_id'] ?? null;

            return $sectionId !== null && in_array($sectionId, $currentCategoryIds);
        });

        $removedCount = count($existingQuestions) - count($validQuestions);

        if ($removedCount > 0) {
            Log::info('[SynthesizeQuestionsJob] Cleaned up stale generated_questions_v2', [
                'exam_id' => $exam->id,
                'original_count' => count($existingQuestions),
                'valid_count' => count($validQuestions),
                'removed_count' => $removedCount,
            ]);

            $task->addActivity('cleanup_stale_questions', "Removed {$removedCount} questions with outdated section_ids", [
                'removed_count' => $removedCount,
                'remaining_count' => count($validQuestions),
            ]);

            // Update meta
            $meta['generated_questions_v2'] = array_values($validQuestions);
            $exam->meta = $meta;
            $exam->save();
        }
    }

    /**
     * Generate audio for question groups (listening sections)
     *
     * @return array{processed: int, audio_generated: int, errors: int, skipped: int}
     */
    private function generateGroupAudio(Exam $exam, GenerationTask $task): array
    {
        // Check if TTS is enabled
        if (! config('ai.tts.enabled', false)) {
            Log::info('TTS disabled, skipping group audio generation', [
                'exam_id' => $exam->id,
            ]);

            return [
                'processed' => 0,
                'audio_generated' => 0,
                'errors' => 0,
                'skipped' => 0,
            ];
        }

        try {
            $task->addActivity('audio_generation_start', 'Starting audio generation for question groups');
            $task->updateHeartbeat();

            // Keep connection alive
            $this->keepConnectionAlive();

            /** @var \App\Services\LanguageApp\QuestionGroupAudioProcessor $audioProcessor */
            $audioProcessor = app(\App\Services\LanguageApp\QuestionGroupAudioProcessor::class);

            $stats = $audioProcessor->processExam($exam);

            $task->addActivity('audio_generation_completed', 'Audio generation completed', [
                'processed' => $stats['processed'],
                'audio_generated' => $stats['audio_generated'],
                'errors' => $stats['errors'],
                'skipped' => $stats['skipped'],
            ]);

            Log::info('Group audio generation completed', [
                'exam_id' => $exam->id,
                'stats' => $stats,
            ]);

            return $stats;

        } catch (\Throwable $e) {
            Log::error('Group audio generation failed', [
                'exam_id' => $exam->id,
                'error' => $e->getMessage(),
            ]);

            $task->addActivity('audio_generation_failed', 'Audio generation failed: '.$e->getMessage());

            // Don't fail the whole job - audio is not critical
            return [
                'processed' => 0,
                'audio_generated' => 0,
                'errors' => 1,
                'skipped' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }
}
