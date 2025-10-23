<?php

namespace App\Jobs;

use App\Jobs\Traits\InteractsWithGenerationTask;
use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\ExamResearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunExamResearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithGenerationTask, InteractsWithQueue, Queueable, SerializesModels;

    public int $taskId;

    public function __construct(
        int $taskId,
        // public string $examId,
        // public ?string $notes = null,
    ) {
        $this->taskId = $taskId;
    }

    public function handle(ExamResearchService $service): void
    {
        /** @var GenerationTask $task */
        // $exam = Exam::findOrFail($this->examId);
        // $exam->update(['research_status' => 'running_overview']);

        // $task = GenerationTask::create([
        //     'exam_id' => $exam->id ?? $this->examId,
        //     'type' => 'research_overview',
        //     'status' => 'running',
        //     'request' => ['exam_id' => $exam->id, 'notes' => $this->notes],
        // ]);

        $task = GenerationTask::findOrFail($this->taskId);
        $this->asRunning($task);
        $this->logStage($task, 'start');

        Log::debug('RunExamResearchJob [task_id]', ['task_id' => $task->id]);

        // try {
        //     $result = $service->runPipeline($exam, $task, $this->notes);
        //     $task->update([
        //         'status' => 'completed',
        //         'result' => $result,
        //     ]);
        //     $exam->update(['research_status' => 'completed']);
        // } catch (\Throwable $e) {
        //     $task->update(['status' => 'failed', 'error' => $e->getMessage()]);
        //     $exam->update(['research_status' => 'failed']);
        //     throw $e;
        // }

        try {
            // exam_id обязателен для pipeline исследования
            $exam = $task->exam ?? null;
            if (! $exam instanceof Exam) {
                throw new \RuntimeException('Exam not found for GenerationTask '.$task->id);
            }

            // Сервис сам оркестрирует overview → categories → examples → rubrics,
            // валидирует JSON и пишет подробные GenerationLog (по T2/T5)
            $result = $service->runPipeline($exam, $task);

            $this->logStage($task, 'end', null, ['meta' => 'pipeline completed']);
            $this->asCompleted($task, $result);
        } catch (\Throwable $e) {
            $this->asFailed($task, $e);
            // Даём Laravel зафейлить job (попадёт в failed_jobs при исчерпании попыток)
            throw $e;
        }
    }
}
