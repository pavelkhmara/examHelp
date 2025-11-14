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

class RunPhaseBJob implements ShouldQueue
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

            $exam->research_status = 'running_overview';
            $exam->save();

            $task->addActivity('phase_b_started', 'Starting Phase B (Assembly v2)');
            $task->updateHeartbeat();

            // Get skeleton from exam
            $skeleton = $exam->structure_v2 ?? null;
            if (! $skeleton) {
                throw new \RuntimeException('Phase A structure_v2 is required before Phase B');
            }

            Log::info('Phase B job started', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
            ]);

            // Run Phase B
            $task->updateHeartbeat();
            $result = $svc->runPhaseB($exam, $task, $skeleton);

            // Save result to exam
            $exam->structure_v2 = $result;
            $exam->research_status = 'completed';
            $exam->save();

            // Update task
            $task->result = $result;
            $task->status = 'completed';
            $task->save();

            $task->addActivity('phase_b_completed', 'Phase B completed successfully - assembly config saved');
            $task->updateHeartbeat();

            Log::info('Phase B job completed', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
                'categories_count' => count($result['categories'] ?? []),
            ]);
        } catch (\Throwable $e) {
            Log::error('Phase B job failed', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $task->addActivity('phase_b_failed', 'Phase B failed: '.$e->getMessage());
            $task->status = 'failed';
            $task->error = 'Phase B failed: '.$e->getMessage();
            $task->save();

            $exam->research_status = 'failed';
            $exam->save();

            throw $e; // Re-throw for retry mechanism
        }
    }
}
