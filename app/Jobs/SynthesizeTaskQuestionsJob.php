<?php

namespace App\Jobs;

use App\Models\Exam;
use App\Models\GenerationPlan;
use App\Models\GenerationTask;
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use \App\Jobs\Concerns\EnsuresConnectionStability;

    public int $timeout = 1800; // 30 min per filter
    public int $tries = 1; // No retries (manual restart only)

    /**
     * @param int $taskId - Parent GenerationTask ID (for logging)
     * @param string $examId - Exam UUID
     * @param int $planId - GenerationPlan ID
     * @param string $filterKey - Unique filter identifier (e.g., "section_listening_filter_0")
     * @param array $filterData - Filter configuration (type, quantity, constraints)
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
                'filter' => $this->filterData,
            ]);

            // Extract filter parameters
            $quantity = $this->filterData['pick'] ?? $this->filterData['quantity'] ?? 1;
            $type = $this->filterData['type'] ?? 'single_select';

            // Get section from exam structure
            $section = $this->getSectionMetadata($exam, $plan->section_id);
            $sectionSkill = $section['skill'] ?? 'unknown';

            Log::info("Generating {$quantity} questions for filter {$this->filterKey} (type: {$type})", [
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

            Log::info("Generated " . count($questions) . " questions for filter {$this->filterKey}", [
                'count' => count($questions),
                'expected' => $quantity,
            ]);

            // Validate and deduplicate
            $validatedQuestions = $validator->validateAndFinalize($questions, $plan, $exam);
            $dedupedQuestions = $deduplicator->detectDuplicates($validatedQuestions, $exam);

            // Attach to exam
            $attachedQuestions = $attacher->attachToExam($dedupedQuestions, $plan, $exam);

            Log::info("Attached " . count($attachedQuestions) . " questions for filter {$this->filterKey}", [
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
                $completedFilters = $meta['filters_completed'] ?? 0;
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
                $plan->error = "Filter {$this->filterKey} failed: " . $e->getMessage();
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
     * Get section metadata from exam structure
     */
    protected function getSectionMetadata(Exam $exam, int $categoryId): array
    {
        $structure = $exam->meta['structure_v2'] ?? null;

        if (! $structure) {
            throw new \Exception('Exam structure_v2 not found');
        }

        $sections = $structure['sections'] ?? [];

        // Find section by category relationship
        $category = \App\Models\ExamCategory::find($categoryId);

        if (! $category) {
            throw new \Exception("ExamCategory not found: {$categoryId}");
        }

        foreach ($sections as $section) {
            if (($section['skill'] ?? null) === $category->skill) {
                return $section;
            }
        }

        throw new \Exception("Section not found for category {$categoryId} (skill: {$category->skill})");
    }
}
