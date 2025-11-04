<?php

namespace App\Jobs;

use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\ExamMetadataAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class AnalyzeExamMetadataJob implements ShouldQueue
{
    use Queueable;

    /**
     * The exam ID to analyze
     */
    public string $examId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $examId)
    {
        $this->examId = $examId;
    }

    /**
     * Execute the job.
     */
    public function handle(ExamMetadataAnalysisService $service): void
    {
        Log::info("AnalyzeExamMetadataJob: Starting for exam {$this->examId}");

        $exam = Exam::find($this->examId);

        if (!$exam) {
            Log::error("AnalyzeExamMetadataJob: Exam not found", ['exam_id' => $this->examId]);
            return;
        }

        // Update status to running
        $exam->update(['analysis_status' => 'running']);

        // Create GenerationTask for logging
        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'metadata_analysis',
            'status' => 'running',
            'request' => [
                'user_input' => $exam->user_input,
                'description' => $exam->description,
                'identity' => $exam->identity,
            ],
        ]);

        try {
            // Run analysis with task for logging
            $result = $service->analyze($exam, $task);

            // Update task status
            $task->update([
                'status' => 'completed',
                'result' => $result,
            ]);

            // Update exam with results
            $exam->update([
                'user_meta' => $result['user_meta'],
                'identity' => $result['identity'],
                'system_analysis' => $result['system_analysis'],
                'analysis_status' => 'completed',
            ]);

            // Auto-generate slug if empty
            if (empty($exam->slug)) {
                $slug = \Illuminate\Support\Str::slug($exam->title);
                $slug = substr($slug, 0, 20); // Max 20 characters

                // Ensure uniqueness
                $originalSlug = $slug;
                $counter = 1;
                while (Exam::where('slug', $slug)->where('id', '!=', $exam->id)->exists()) {
                    $slug = substr($originalSlug, 0, 17) . '-' . $counter;
                    $counter++;
                }

                $exam->update(['slug' => $slug]);
            }

            Log::info("AnalyzeExamMetadataJob: Completed successfully for exam {$this->examId}");
        } catch (\Exception $e) {
            Log::error("AnalyzeExamMetadataJob: Failed", [
                'exam_id' => $this->examId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update task status
            if (isset($task)) {
                $task->update([
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ]);
            }

            $exam->update([
                'analysis_status' => 'failed',
                'system_analysis' => [
                    'critical' => ['Analysis failed: ' . $e->getMessage()],
                ],
            ]);
        }
    }
}
