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

/**
 * Generate Example Questions Job
 *
 * Generates sample questions for each question archetype in the exam structure.
 * Uses ExamResearchService::generateExamples()
 */
class GenerateExamplesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 900; // 15 minutes

    public function __construct(public int $taskId) {}

    public function handle(ExamResearchService $svc): void
    {
        $task = GenerationTask::query()->findOrFail($this->taskId);
        /** @var Exam $exam */
        $exam = Exam::query()->findOrFail($task->exam_id);

        Log::info('GenerateExamplesJob started', [
            'task_id' => $task->id,
            'exam_id' => $exam->id,
        ]);

        try {
            $task->status = 'running';
            $task->save();

            $task->addActivity('examples_started', 'Starting example generation');
            $task->updateHeartbeat();

            // Get examples_per_archetype from request (default: 1)
            $examplesPerArchetype = $task->request['examples_per_archetype'] ?? 1;

            // Generate examples
            $result = $svc->generateExamples($exam, $task, $examplesPerArchetype);

            $task->updateHeartbeat();

            $examplesCount = $result['examples_created'] ?? 0;
            $task->addActivity('examples_completed', "Generated {$examplesCount} example questions", [
                'examples_count' => $examplesCount,
            ]);

            $task->status = 'completed';
            $task->result = [
                'success' => true,
                'examples' => $result,
            ];
            $task->save();

            Log::info('✅ GenerateExamplesJob completed', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
                'examples_count' => $examplesCount,
            ]);
        } catch (\Throwable $e) {
            Log::error('GenerateExamplesJob failed', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $task->addActivity('examples_failed', 'Example generation failed: ' . $e->getMessage());

            $task->status = 'failed';
            $task->error = 'Example generation failed: ' . $e->getMessage();
            $task->save();

            throw $e;
        }
    }
}
