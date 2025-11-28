<?php

namespace App\Jobs;

use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\AssemblyResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ResolveGenerationPlansJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600; // 10 minutes

    public function __construct(public int $taskId) {}

    public function handle(AssemblyResolver $resolver): void
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

            $task->addActivity('resolve_plans_started', 'Starting generation plan resolution');
            $task->updateHeartbeat();

            Log::info('Resolve plans job started', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
            ]);

            // Run plan resolution
            $task->updateHeartbeat();
            $plans = $resolver->resolve($exam); // Returns array of GenerationPlan models

            // Update task
            $task->result = [
                'success' => true,
                'plans_count' => count($plans),
                'plans' => collect($plans)->map(fn ($p) => [
                    'id' => $p->id,
                    'section_id' => $p->section_id,
                    'assembly_mode' => $p->assembly_mode,
                    'total_questions' => $p->total_questions,
                    'status' => $p->status,
                ])->toArray(),
            ];
            $task->status = 'completed';
            $task->save();

            $task->addActivity('resolve_plans_completed', 'Plan resolution completed successfully', [
                'plans_count' => count($plans),
            ]);
            $task->updateHeartbeat();

            Log::info('Resolve plans job completed', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
                'plans_count' => count($plans),
            ]);
        } catch (\Throwable $e) {
            Log::error('Resolve plans job failed', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $task->addActivity('resolve_plans_failed', 'Plan resolution failed: '.$e->getMessage());
            $task->status = 'failed';
            $task->error = 'Plan resolution failed: '.$e->getMessage();
            $task->save();

            throw $e; // Re-throw for retry mechanism
        }
    }
}
