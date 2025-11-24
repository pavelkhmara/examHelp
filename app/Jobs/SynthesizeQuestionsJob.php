<?php

namespace App\Jobs;

use App\Models\Exam;
use App\Models\GenerationPlan;
use App\Models\GenerationTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SynthesizeQuestionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 1800; // 30 minutes for AI-heavy task

    public function __construct(public int $taskId) {}

    public function handle(): void
    {
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
                    throw new \Exception('Requested generation plans not found: ' . implode(', ', $planIds));
                }
            } else {
                // Load all pending/failed plans for this exam
                Log::info('Atomically claiming pending/failed plans', [
                    'exam_id' => $exam->id,
                ]);

                // Atomic update - claim all pending/failed plans
                $updated = GenerationPlan::where('exam_id', $exam->id)
                    ->whereIn('status', ['pending', 'failed'])
                    ->update([
                        'status' => 'in_progress',
                        'started_at' => DB::raw('COALESCE(started_at, NOW())'),
                    ]);

                // Load claimed plans
                $plans = GenerationPlan::where('exam_id', $exam->id)
                    ->where('status', 'in_progress')
                    ->whereNotNull('started_at')
                    ->where('started_at', '>=', now()->subSeconds(5)) // Only recent claims
                    ->get();

                Log::info('Loaded pending/failed plans', [
                    'updated' => $updated,
                    'found' => $plans->count(),
                ]);

                if ($plans->isEmpty()) {
                    throw new \Exception('No generation plans found (pending or failed). All plans may already be completed.');
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
            $results = $this->synthesizeInParallel($task, $exam, $plans);

            $totalGenerated = collect($results)->sum('generated');
            $totalAttached = collect($results)->sum('attached');

            // Update task
            $task->result = [
                'success' => true,
                'plans_processed' => $plans->count(),
                'total_generated' => $totalGenerated,
                'total_attached' => $totalAttached,
                'results' => $results,
            ];
            $task->status = 'completed';
            $task->save();

            $task->addActivity('synthesize_completed', 'Question synthesis completed successfully', [
                'plans_processed' => $plans->count(),
                'total_generated' => $totalGenerated,
                'total_attached' => $totalAttached,
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
     */
    private function synthesizeInParallel(
        GenerationTask $task,
        Exam $exam,
        $plans
    ): array {
        // Use ParallelQuestionSynthesizer for parallel AI requests
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
}
