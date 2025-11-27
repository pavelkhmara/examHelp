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
use Illuminate\Support\Facades\Log;

class RunPhaseAJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600; // 10 minutes for complex AI reasoning

    public function __construct(public int $taskId) {}

    public function handle(ExamResearchService $svc): void
    {
        $task = GenerationTask::query()->findOrFail($this->taskId);
        /** @var Exam $exam */
        $exam = Exam::query()->findOrFail($task->exam_id);

        // Prevent duplicate execution when Job is retried or dispatched multiple times
        if (in_array($task->status, ['running', 'completed', 'pending_confirmation', 'pending_clarification'], true)) {
            Log::info('Job stopped: task already processing or finished', [
                'task_id' => $task->id,
                'status' => $task->status,
                'attempt' => $this->attempts(),
            ]);

            $task->addActivity('job_stopped_duplicate', 'Phase A job execution prevented - task already processing or finished', [
                'status' => $task->status,
                'attempt' => $this->attempts(),
            ]);

            return;
        }

        try {
            // Set status to running
            $task->status = 'running';
            $task->save();

            $exam->research_status = 'running_phase_a';
            $exam->save();

            $task->addActivity('phase_a_started', 'Starting Phase A (Skeleton v2)');
            $task->updateHeartbeat();

            Log::info('Phase A job started', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
            ]);

            // Run Phase A
            $task->updateHeartbeat();
            $result = $svc->runPhaseA($exam, $task);

            // Save result to exam
            $exam->structure_v2 = $result;
            $exam->research_status = 'phase_a_completed'; // Only skeleton, not full research
            $exam->save();

            // Update task
            $task->result = $result;
            $task->status = 'completed';
            $task->save();

            $task->addActivity('phase_a_completed', 'Phase A completed successfully - skeleton saved. Run Phase B next to generate assembly plans.');
            $task->updateHeartbeat();

            Log::info('Phase A job completed', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
                'categories_count' => count($result['categories'] ?? []),
            ]);
        } catch (\Throwable $e) {
            Log::error('Phase A job failed', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $task->addActivity('phase_a_failed', 'Phase A failed: '.$e->getMessage());
            $task->status = 'failed';
            $task->error = 'Phase A failed: '.$e->getMessage();
            $task->save();

            $exam->research_status = 'failed';
            $exam->save();

            throw $e; // Re-throw for retry mechanism
        }
    }
}
