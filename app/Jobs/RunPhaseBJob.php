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

        // Prevent duplicate execution when Job is retried or dispatched multiple times
        if (in_array($task->status, ['running', 'completed', 'pending_confirmation', 'pending_clarification'], true)) {
            Log::info('Job stopped: task already processing or finished', [
                'task_id' => $task->id,
                'status' => $task->status,
                'attempt' => $this->attempts(),
            ]);

            $task->addActivity('job_stopped_duplicate', 'Phase B job execution prevented - task already processing or finished', [
                'status' => $task->status,
                'attempt' => $this->attempts(),
            ]);

            return;
        }

        try {
            // Set status to running
            $task->status = 'running';
            $task->save();

            $exam->research_status = 'running_phase_b';
            $exam->save();

            $task->addActivity('phase_b_started', 'Starting Phase B (Assembly v2) - parallel mode');
            $task->updateHeartbeat();

            // Get skeleton from exam
            $skeleton = $exam->structure_v2 ?? null;
            if (! $skeleton) {
                throw new \RuntimeException('Phase A structure_v2 is required before Phase B');
            }

            $sections = $skeleton['sections'] ?? [];
            if (empty($sections)) {
                throw new \RuntimeException('No sections found in Phase A skeleton');
            }

            Log::info('Phase B job started - parallel section generation', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
                'sections_count' => count($sections),
            ]);

            // === PARALLEL SECTION GENERATION ===
            // Dispatch GenerateSectionAssemblyJob for each section
            $sectionIds = array_column($sections, 'id');

            // Initialize sections_status tracking
            $sectionsStatus = [];
            foreach ($sectionIds as $sid) {
                $sectionsStatus[$sid] = 'pending';
            }

            $task->request = array_merge($task->request ?? [], [
                'expected_sections' => $sectionIds,
                'sections_status' => $sectionsStatus,
            ]);
            $task->save();

            $task->addActivity('dispatching_sections', "Dispatching " . count($sections) . " section assembly jobs (parallel)", [
                'sections' => $sectionIds,
                'mode' => 'parallel',
            ]);

            foreach ($sections as $section) {
                GenerateSectionAssemblyJob::dispatch($task->id, $section['id'], $section);
            }

            // Dispatch coordinator job to wait for all sections and finalize
            $task->addActivity('finalize_job_dispatched', 'Coordinator job dispatched - will wait for all sections to complete');
            FinalizeSectionGenerationJob::dispatch($task->id)->delay(now()->addSeconds(30));

            $task->updateHeartbeat();

            Log::info('Phase B parallel jobs dispatched', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
                'sections' => $sectionIds,
            ]);

            // NOTE: Task completion happens in FinalizeSectionGenerationJob
            // This job ends here after dispatching all section jobs

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
