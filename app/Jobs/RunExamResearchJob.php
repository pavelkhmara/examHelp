<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;
use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\ExamResearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunExamResearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $examId,
        public ?string $notes = null,
    ) {}

    public function handle(ExamResearchService $service): void
    {
        $exam = Exam::findOrFail($this->examId);
        $exam->update(['research_status' => 'running_overview']);

        $task = GenerationTask::create([
            'exam_id' => $exam->id ?? $this->examId,
            'type' => 'research_overview',
            'status' => 'running',
            'request' => ['exam_id' => $exam->id, 'notes' => $this->notes],
        ]);

        Log::debug('RunExamResearchJob [task_id]', [ 'task_id' => $task->id]);

        try {
            $result = $service->runPipeline($exam, $task, $this->notes);
            $task->update([
                'status' => 'completed',
                'result' => $result,
            ]);
            $exam->update(['research_status' => 'completed']);
        } catch (\Throwable $e) {
            $task->update(['status' => 'failed', 'error' => $e->getMessage()]);
            $exam->update(['research_status' => 'failed']);
            throw $e;
        }
    }
}
