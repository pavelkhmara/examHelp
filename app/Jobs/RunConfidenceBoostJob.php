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

class RunConfidenceBoostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300; // 5 minutes for AI request

    public function __construct(public int $taskId) {}

    public function handle(ExamResearchService $svc): void
    {
        $task = GenerationTask::query()->findOrFail($this->taskId);
        /** @var Exam $exam */
        $exam = Exam::query()->findOrFail($task->exam_id);

        // Prevent duplicate execution
        if (in_array($task->status, ['pending_confirmation', 'completed'], true)) {
            Log::info('Job stopped: task already in final state', [
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

            $task->addActivity('confidence_boost_started', 'Starting confidence boost');
            $task->updateHeartbeat();

            // Get identity from task result
            $identity = $task->result['identity'] ?? null;
            if (! $identity) {
                throw new \RuntimeException('No identity data found in task result');
            }

            $originalConfidence = $identity['confidence'] ?? 0.0;

            // Validate confidence is in boostable range
            if ($originalConfidence < 0.80) {
                throw new \RuntimeException("Confidence too low ({$originalConfidence}) - cannot boost");
            }

            if ($originalConfidence >= 0.97) {
                // Already high - just mark as completed and continue
                $task->addActivity('confidence_boost_skipped', "Confidence already high ({$originalConfidence})", [
                    'confidence' => $originalConfidence,
                ]);

                $task->status = 'queued';
                $task->save();

                // Continue to overview pipeline
                RunExamResearchJob::dispatch($task->id)->delay(now()->addSeconds(1));

                return;
            }

            // Check if already boosted
            if (isset($identity['confidence_boosted_at'])) {
                throw new \RuntimeException('Confidence has already been boosted');
            }

            // Run confidence boost
            $task->updateHeartbeat();
            $boostedIdentity = $svc->runConfidenceBoost($exam, $task, $identity);

            // Update task with boosted identity
            $result = (array) ($task->result ?? []);
            $result['identity'] = $boostedIdentity;
            $task->result = $result;
            $task->save();

            // Update exam->identity
            $exam->identity = $boostedIdentity;
            $exam->save();

            $boostedConfidence = $boostedIdentity['confidence'] ?? 0.0;

            $task->addActivity('confidence_boost_completed', "Confidence boosted to: {$boostedConfidence}", [
                'original_confidence' => $originalConfidence,
                'boosted_confidence' => $boostedConfidence,
            ]);

            Log::info('Confidence boost completed', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
                'original' => $originalConfidence,
                'boosted' => $boostedConfidence,
            ]);

            // Determine next step based on boosted confidence
            if ($boostedConfidence >= 0.97) {
                // High enough - continue to overview
                $task->status = 'queued';
                $task->save();

                $exam->research_status = 'queued';
                $exam->save();

                $task->addActivity('pipeline_continuing', 'Confidence is high enough, continuing to overview pipeline');

                // Dispatch next stage
                RunExamResearchJob::dispatch($task->id)->delay(now()->addSeconds(1));
            } else {
                // Still not high enough - wait for confirmation
                $task->status = 'pending_confirmation';
                $task->save();

                $exam->research_status = 'queued'; // exam uses limited enum, task uses pending_confirmation
                $exam->save();

                $task->addActivity('boost_insufficient', "Confidence boosted but still below 0.97 ({$boostedConfidence}), waiting for manual confirmation");
            }

            $task->updateHeartbeat();
        } catch (\Throwable $e) {
            Log::error('Confidence boost job failed', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $task->addActivity('confidence_boost_failed', 'Confidence boost failed: '.$e->getMessage());
            $task->status = 'failed';
            $task->error = 'Confidence boost failed: '.$e->getMessage();
            $task->save();

            $exam->research_status = 'failed';
            $exam->save();

            throw $e; // Re-throw for retry mechanism
        }
    }
}
