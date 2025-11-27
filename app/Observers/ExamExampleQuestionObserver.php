<?php

namespace App\Observers;

use App\Models\ExamExampleQuestion;
use App\Services\LanguageApp\ExamStructureRecoveryService;
use Illuminate\Support\Facades\Log;

/**
 * Observer for ExamExampleQuestion model
 *
 * Automatically checks and recovers exam_structure when example questions are created
 */
class ExamExampleQuestionObserver
{
    /**
     * Handle the ExamExampleQuestion "created" event.
     *
     * When a new example question is created, check if exam_structure needs recovery
     */
    public function created(ExamExampleQuestion $exampleQuestion): void
    {
        // Skip if no exam_category_id (shouldn't happen, but be safe)
        if (! $exampleQuestion->exam_category_id) {
            return;
        }

        // Get the exam through category
        $category = $exampleQuestion->examCategory;
        if (! $category || ! $category->exam) {
            return;
        }

        $exam = $category->exam;

        // Check if recovery is needed
        $recovery = app(ExamStructureRecoveryService::class);

        if ($recovery->needsRecovery($exam)) {
            Log::info('ExamExampleQuestionObserver: Detected missing exam_structure, triggering recovery', [
                'exam_id' => $exam->id,
                'example_question_id' => $exampleQuestion->id,
            ]);

            try {
                $result = $recovery->recover($exam, false);

                if ($result['needed_recovery']) {
                    Log::info('ExamExampleQuestionObserver: Successfully recovered exam_structure', [
                        'exam_id' => $exam->id,
                        'recovered_archetypes' => $result['recovered_archetypes'],
                        'recovered_sections' => $result['recovered_sections'],
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('ExamExampleQuestionObserver: Failed to recover exam_structure', [
                    'exam_id' => $exam->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }
}
