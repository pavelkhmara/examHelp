<?php

namespace App\Jobs;

use App\Models\Exam;
use App\Models\GenerationPlan;
use App\Models\GenerationTask;
use App\Services\LanguageApp\Contracts\QuestionGroupContract;
use App\Services\LanguageApp\QuestionAttacher;
use App\Services\LanguageApp\QuestionDeduplicator;
use App\Services\LanguageApp\QuestionSynthesizer;
use App\Services\LanguageApp\QuestionValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generates questions for a SINGLE filter/slot (task-level parallelization).
 *
 * Responsibilities:
 * - Load exam, section, plan
 * - Call QuestionSynthesizer::generateQuestionBatch() for ONE filter
 * - Validate, deduplicate, attach questions
 * - Update plan.meta['questions_generated'] atomically
 * - Mark plan as completed when all filters done
 *
 * Dispatched by: SynthesizeQuestionsJob (one job per filter)
 * Parallelization: Multiple workers process different filters concurrently
 */
class SynthesizeTaskQuestionsJob implements ShouldQueue
{
    use \App\Jobs\Concerns\EnsuresConnectionStability;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 min per filter

    public int $tries = 3; // Allow retries with backoff for transient failures

    public int $backoff = 60; // Wait 60 seconds between retries

    /**
     * @param  int  $taskId  - Parent GenerationTask ID (for logging)
     * @param  string  $examId  - Exam UUID
     * @param  int  $planId  - GenerationPlan ID
     * @param  string  $filterKey  - Unique filter identifier (e.g., "section_listening_filter_0")
     * @param  array  $filterData  - Filter configuration (type, quantity, constraints)
     */
    public function __construct(
        public int $taskId,
        public string $examId,
        public int $planId,
        public string $filterKey,
        public array $filterData
    ) {
        $this->onQueue('default');
    }

    public function handle(
        QuestionSynthesizer $synthesizer,
        QuestionValidator $validator,
        QuestionDeduplicator $deduplicator,
        QuestionAttacher $attacher
    ): void {
        // Ensure database connection is stable
        $this->ensureConnection();

        $task = GenerationTask::findOrFail($this->taskId);
        $exam = Exam::findOrFail($this->examId);
        $plan = GenerationPlan::lockForUpdate()->findOrFail($this->planId);

        // Check if this filter already completed
        $completedFilters = $plan->meta['completed_filters'] ?? [];
        if (in_array($this->filterKey, $completedFilters)) {
            Log::info('SynthesizeTaskQuestionsJob: filter already completed, skipping', [
                'task_id' => $this->taskId,
                'plan_id' => $this->planId,
                'filter_key' => $this->filterKey,
            ]);

            return;
        }

        try {
            Log::info('SynthesizeTaskQuestionsJob started', [
                'task_id' => $this->taskId,
                'plan_id' => $this->planId,
                'filter_key' => $this->filterKey,
                'filter_type' => $this->filterData['type'] ?? 'unknown',
            ]);

            // Get section from exam structure
            /** @var string|int $sectionId */
            $sectionId = $plan->section_id;
            $section = $this->getSectionMetadata($exam, $sectionId);
            $sectionSkill = $section['skill'] ?? 'unknown';

            // Check if this is a question_group filter (inline mode)
            $filterType = $this->filterData['type'] ?? 'single_select';

            if ($filterType === 'question_group') {
                // Handle question_group filter (inline mode with groups)
                $questions = $this->synthesizeQuestionGroup($exam, $section, $sectionSkill, $plan, $synthesizer);
            } else {
                // Handle pool/blueprint filter (standard mode)
                $quantity = $this->filterData['pick'] ?? $this->filterData['quantity'] ?? 1;

                Log::info("Generating {$quantity} questions for filter {$this->filterKey} (type: {$filterType})", [
                    'plan_id' => $this->planId,
                    'section_skill' => $sectionSkill,
                ]);

                // Generate questions for THIS filter only (batch mode)
                $questions = $synthesizer->generateQuestionBatch(
                    exam: $exam,
                    section: $section,
                    sectionSkill: $sectionSkill,
                    quantity: $quantity,
                    filters: [$this->filterData], // Single filter
                    plan: $plan
                );
            }

            // DEBUG: Check group_id in generated questions
            Log::info('[SynthesizeTaskQuestionsJob] Questions after generation', [
                'filter_key' => $this->filterKey,
                'filter_type' => $filterType,
                'questions' => array_map(function ($q) {
                    return [
                        'id' => $q['id'] ?? 'no-id',
                        'group_id' => $q['group_id'] ?? 'NO-GROUP-ID',
                        'type' => $q['type'] ?? 'no-type',
                    ];
                }, $questions),
            ]);

            $expectedCount = $this->filterData['pick'] ?? $this->filterData['quantity'] ?? count($this->filterData['questions'] ?? []) ?: 1;
            Log::info('Generated '.count($questions)." questions for filter {$this->filterKey}", [
                'count' => count($questions),
                'expected' => $expectedCount,
            ]);

            // Validate and deduplicate
            $validatedQuestions = $validator->validateAndFinalize($questions, $plan, $exam);
            $dedupedQuestions = $deduplicator->detectDuplicates($validatedQuestions, $exam);

            // Attach to exam
            $attachedQuestions = $attacher->attachToExam($dedupedQuestions, $plan, $exam);

            Log::info('Attached '.count($attachedQuestions)." questions for filter {$this->filterKey}", [
                'generated' => count($questions),
                'validated' => count($validatedQuestions),
                'deduped' => count($dedupedQuestions),
                'attached' => count($attachedQuestions),
            ]);

            // Update plan metadata (atomic)
            DB::transaction(function () use ($plan, $attachedQuestions, $task) {
                $plan->refresh();
                $meta = $plan->meta ?? [];
                $meta['questions_generated'] = ($meta['questions_generated'] ?? 0) + count($attachedQuestions);
                $meta['filters_completed'] = ($meta['filters_completed'] ?? 0) + 1;
                $meta['completed_filters'] = array_merge($meta['completed_filters'] ?? [], [$this->filterKey]);
                $plan->meta = $meta;
                $plan->save();

                // Add activity log to parent task
                $totalFilters = $meta['total_filters'] ?? 0;
                $completedFilters = $meta['filters_completed'];
                $task->addActivity('filter_completed', "Filter {$this->filterKey} completed", [
                    'filter_key' => $this->filterKey,
                    'questions_attached' => count($attachedQuestions),
                    'progress' => "{$completedFilters}/{$totalFilters}",
                ]);
            });

            // Check if ALL filters completed
            $plan->refresh();
            $totalFilters = $plan->meta['total_filters'] ?? 0;
            $completedFilters = $plan->meta['filters_completed'] ?? 0;

            if ($completedFilters >= $totalFilters) {
                Log::info("All filters completed for plan {$this->planId}, marking as completed");
                $plan->markAsCompleted();

                $task->addActivity('plan_completed', "Plan #{$this->planId} completed", [
                    'plan_id' => $this->planId,
                    'total_filters' => $totalFilters,
                    'total_questions' => $plan->meta['questions_generated'] ?? 0,
                ]);
            }

            Log::info('SynthesizeTaskQuestionsJob completed successfully', [
                'task_id' => $this->taskId,
                'plan_id' => $this->planId,
                'filter_key' => $this->filterKey,
                'questions_attached' => count($attachedQuestions),
                'filters_completed' => "{$completedFilters}/{$totalFilters}",
            ]);

        } catch (\Throwable $e) {
            Log::error('SynthesizeTaskQuestionsJob failed', [
                'task_id' => $this->taskId,
                'plan_id' => $this->planId,
                'filter_key' => $this->filterKey,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark filter as failed in plan.meta
            DB::transaction(function () use ($plan, $e, $task) {
                $plan->refresh();
                $meta = $plan->meta ?? [];
                $meta['failed_filters'] = $meta['failed_filters'] ?? [];
                $meta['failed_filters'][$this->filterKey] = [
                    'error' => $e->getMessage(),
                    'timestamp' => now()->toIso8601String(),
                ];
                $plan->meta = $meta;
                $plan->status = 'failed';
                $plan->error = "Filter {$this->filterKey} failed: ".$e->getMessage();
                $plan->save();

                $task->addActivity('filter_failed', "Filter {$this->filterKey} failed", [
                    'filter_key' => $this->filterKey,
                    'error' => $e->getMessage(),
                ]);
            });

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SynthesizeTaskQuestionsJob failed permanently', [
            'task_id' => $this->taskId,
            'plan_id' => $this->planId,
            'filter_key' => $this->filterKey,
            'exception' => $exception->getMessage(),
        ]);
    }

    /**
     * Get section metadata from ExamCategory (PRIMARY source of truth)
     *
     * FIX: Changed to read from ExamCategory instead of structure_v2
     * See: docs/fixes/fix-dual-source-of-truth.md
     */
    protected function getSectionMetadata(Exam $exam, string|int $categoryId): array
    {
        // Cast to int if string (GenerationPlan.section_id is string but ExamCategory.id is int)
        $categoryId = is_string($categoryId) ? (int) $categoryId : $categoryId;
        $category = \App\Models\ExamCategory::find($categoryId);

        if (! $category) {
            throw new \Exception("ExamCategory not found: {$categoryId}");
        }

        $meta = $category->meta ?? [];

        return [
            'id' => $category->key ?? "sec-{$category->id}",
            'title' => $category->name,
            'skill' => $category->skill,
            'duration_min' => $category->duration_min,
            'max_score' => $category->max_score,
            'description' => $category->description,
            'questions' => $meta['questions'] ?? [],
            'assembly' => $meta['assembly'] ?? [],
            'question_archetypes' => $meta['question_archetypes'] ?? [],
        ];
    }

    /**
     * Synthesize questions for a question_group (inline mode).
     *
     * Each question_group contains multiple question specs that share a stimulus.
     * We generate all questions in the group in one AI request to maintain context.
     *
     * @return array Generated questions
     */
    protected function synthesizeQuestionGroup(
        Exam $exam,
        array $section,
        string $sectionSkill,
        GenerationPlan $plan,
        QuestionSynthesizer $synthesizer
    ): array {
        $groupId = $this->filterData['group_id'] ?? 'unknown';
        $groupTitle = $this->filterData['group_title'] ?? '';
        $groupStimulus = $this->filterData['group_stimulus'] ?? [];
        $groupInstructions = $this->filterData['group_instructions'] ?? [];
        $groupQuestions = $this->filterData['questions'] ?? [];
        $quantity = count($groupQuestions);

        Log::info("Synthesizing question group: {$groupId}", [
            'plan_id' => $this->planId,
            'group_title' => $groupTitle,
            'questions_count' => $quantity,
            'section_skill' => $sectionSkill,
        ]);

        if ($quantity === 0) {
            Log::warning("Empty question group: {$groupId}");

            return [];
        }

        // Build filters array from group questions
        // Each question in the group becomes a filter for the synthesizer
        $filters = [];
        foreach ($groupQuestions as $qIndex => $q) {
            $filter = [
                'type' => $q['type'] ?? 'single_select',
                'pick' => 1,
                'config' => $q['config'] ?? [],
                'group_id' => $groupId,
                'group_title' => $groupTitle,
                'group_stimulus' => $groupStimulus,
                'group_instructions' => $groupInstructions,
                'order_in_group' => $qIndex,
                // CRITICAL: Pass original question_id for skeleton matching
                'question_id' => $q['id'] ?? "q{$qIndex}",
            ];

            // ✅ VALIDATE CONTRACT: Ensure filter meets contract requirements
            QuestionGroupContract::validateFilter($filter);

            $filters[] = $filter;
        }

        // ✅ CHECKPOINT LOGGING: Log filters before synthesis
        foreach ($filters as $index => $filter) {
            Log::info('[Contract:Filter] Built for synthesis', [
                'filter_index' => $index,
                'question_id' => $filter['question_id'],
                'group_id' => $filter['group_id'],
                'type' => $filter['type'],
            ]);
        }

        // Generate all questions in this group
        $questions = $synthesizer->generateQuestionBatch(
            exam: $exam,
            section: $section,
            sectionSkill: $sectionSkill,
            quantity: $quantity,
            filters: $filters,
            plan: $plan
        );

        // Add group context to generated questions
        // Match generated questions with their filter specs to preserve question_id
        foreach ($questions as $qIndex => &$question) {
            $filter = $filters[$qIndex] ?? null;

            // CRITICAL: Use question_id from filter (matches skeleton in DB)
            if ($filter && isset($filter['question_id'])) {
                $question['id'] = $filter['question_id'];
            }

            // Ensure group_id is set for proper association
            if (! isset($question['group_id'])) {
                $question['group_id'] = $groupId;
            }
            // Add stimulus from group if not set on question
            if (empty($question['stimulus']) && ! empty($groupStimulus)) {
                $question['stimulus'] = $groupStimulus;
            }
        }

        Log::info("Question group {$groupId} synthesis complete", [
            'generated' => count($questions),
            'expected' => $quantity,
        ]);

        return $questions;
    }
}
