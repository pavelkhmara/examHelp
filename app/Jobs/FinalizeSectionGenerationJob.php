<?php

namespace App\Jobs;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\GenerationTask;
use App\Services\LanguageApp\ExamResearchService;
use App\Services\LanguageApp\SanityCheckerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Finalize section generation after all parallel GenerateSectionAssemblyJob jobs complete
 *
 * This job waits for all section assembly jobs to complete, then:
 * 1. Runs sanity checker
 * 2. Generates example questions
 * 3. Creates ExamCategory records
 * 4. Completes the research task
 */
class FinalizeSectionGenerationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 20; // Allow many retries for waiting
    public int $timeout = 1800; // 30 minutes total
    public int $backoff = 30; // 30 seconds between retries

    public function __construct(public int $taskId) {}

    public function handle(ExamResearchService $svc, SanityCheckerService $sanityChecker): void
    {
        $task = GenerationTask::query()->findOrFail($this->taskId);
        /** @var Exam $exam */
        $exam = Exam::query()->findOrFail($task->exam_id);

        $expectedSections = $task->request['expected_sections'] ?? [];
        $sectionsStatus = $task->request['sections_status'] ?? [];

        Log::info('FinalizeSectionGenerationJob checking progress', [
            'task_id' => $task->id,
            'exam_id' => $exam->id,
            'expected_sections' => $expectedSections,
            'sections_status' => $sectionsStatus,
            'attempt' => $this->attempts(),
        ]);

        // Check if all sections are completed
        $completedCount = 0;
        $failedCount = 0;
        foreach ($expectedSections as $sectionId) {
            $status = $sectionsStatus[$sectionId] ?? 'pending';
            if ($status === 'completed') {
                $completedCount++;
            } elseif ($status === 'failed') {
                $failedCount++;
            }
        }

        $totalSections = count($expectedSections);

        Log::info('Section generation progress', [
            'task_id' => $task->id,
            'completed' => $completedCount,
            'failed' => $failedCount,
            'total' => $totalSections,
        ]);

        // If any sections failed, fail the task
        if ($failedCount > 0) {
            $task->addActivity('finalization_failed', "Section generation failed: {$failedCount}/{$totalSections} sections failed");

            $task->status = 'failed';
            $task->error = "Section generation failed: {$failedCount} of {$totalSections} sections failed";
            $task->save();

            $exam->research_status = 'failed';
            $exam->save();

            Log::error('Section generation failed', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
                'failed_count' => $failedCount,
            ]);

            return;
        }

        // If not all sections are completed, retry later
        if ($completedCount < $totalSections) {
            $task->addActivity('finalization_waiting', "Waiting for sections: {$completedCount}/{$totalSections} completed (attempt {$this->attempts()})");

            Log::info('Not all sections ready, retrying later', [
                'task_id' => $task->id,
                'completed' => $completedCount,
                'total' => $totalSections,
                'attempt' => $this->attempts(),
            ]);

            // Retry after 30 seconds
            $this->release($this->backoff);
            return;
        }

        // All sections completed! Start finalization
        Log::info('All sections completed, starting finalization', [
            'task_id' => $task->id,
            'exam_id' => $exam->id,
            'sections_count' => $totalSections,
        ]);

        try {
            $task->addActivity('finalization_started', 'All sections completed, starting finalization');
            $task->updateHeartbeat();

            // Get complete structure from exam.meta
            $exam->refresh();
            $structure = $exam->meta['structure_v2'] ?? [];
            $sections = $structure['sections'] ?? [];

            // Run sanity checker
            $identitySnapshot = $task->result['identity'] ?? null;

            if (!empty($sections) && $identitySnapshot) {
                $examDocDraft = [
                    'canonical' => $identitySnapshot['canonical'] ?? [],
                    'sections' => $sections,
                    'administration' => [
                        'total_time_minutes' => $structure['total_exam_duration'] ?? null,
                    ],
                    'scoring' => [
                        'scale' => $structure['scoring_scale'] ?? null,
                    ],
                ];

                $sanityResult = $sanityChecker->runSanityChecker($exam, $task, $examDocDraft);
                $complianceScore = $sanityResult['compliance_score'] ?? 0;

                if ($complianceScore < 0.85) {
                    $task->addActivity('decision_point_sanity_check', "Условие: compliance_score < 0.85 (текущий: {$complianceScore}). Решение: добавить warning и продолжить к генерации примеров", [
                        'condition' => 'compliance_score < 0.85',
                        'compliance_score' => $complianceScore,
                        'decision' => 'add_warning_continue',
                    ]);

                    $result = (array) ($task->result ?? []);
                    $result['sanity_check'] = $sanityResult;
                    $result['warnings'] = array_merge(
                        $result['warnings'] ?? [],
                        ['Low compliance with exam family invariants: ' . $complianceScore]
                    );
                    $task->result = $result;
                    $task->save();
                } else {
                    $task->addActivity('decision_point_sanity_check', "Условие: compliance_score >= 0.85 (текущий: {$complianceScore}). Решение: продолжить к генерации примеров", [
                        'condition' => 'compliance_score >= 0.85',
                        'compliance_score' => $complianceScore,
                        'decision' => 'continue_to_examples',
                    ]);
                }
            }

            // Generate example questions
            try {
                $task->addActivity('example_generation_started', 'Generating example questions for archetypes');
                $task->updateHeartbeat();

                $exampleResult = $svc->generateExamples($exam, $task, 1);

                $task->updateHeartbeat();

                $examplesCount = $exampleResult['examples_created'] ?? 0;
                $task->addActivity('example_generation_completed', "Generated {$examplesCount} example questions", [
                    'examples_count' => $examplesCount,
                ]);

                $result = (array) ($task->result ?? []);
                $result['examples'] = $exampleResult;
                $task->result = $result;
                $task->save();
            } catch (\Throwable $e) {
                Log::error('Example generation failed', [
                    'exam_id' => $exam->id,
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);

                $task->addActivity('example_generation_failed', 'Example generation failed: ' . $e->getMessage());

                $result = (array) ($task->result ?? []);
                $result['examples_error'] = $e->getMessage();
                $task->result = $result;
                $task->save();
            }

            // Create ExamCategory records from structure_v2 sections
            $task->addActivity('creating_categories', 'Creating ExamCategory records from structure_v2 sections');

            // Delete all existing categories for this exam (fresh start)
            $deletedCount = ExamCategory::where('exam_id', $exam->id)->delete();
            if ($deletedCount > 0) {
                Log::info('Deleted old categories before creating new ones', [
                    'exam_id' => $exam->id,
                    'deleted_count' => $deletedCount,
                ]);
                $task->addActivity('categories_deleted', "Deleted {$deletedCount} old ExamCategory records");
            }

            foreach ($sections as $section) {
                $sectionName = $section['id'];

                // Create ExamCategory
                ExamCategory::create([
                    'exam_id' => $exam->id,
                    'key' => $sectionName,
                    'name' => $sectionName,
                    'skill' => $section['skill'] ?? null,
                    'duration_min' => $section['duration_min'] ?? null,
                    'max_score' => $section['max_score'] ?? null,
                    'min_pass_percent' => $section['min_pass_percent'] ?? null,
                    'question_archetypes' => $section['question_archetypes'] ?? [],
                    'meta' => [
                        'tasks' => $section['tasks'] ?? [],
                        'assembly' => $section['assembly'] ?? null,
                    ],
                ]);

                Log::info('Created ExamCategory from v2 section', [
                    'exam_id' => $exam->id,
                    'section_name' => $sectionName,
                ]);
            }

            $categoriesCount = count($sections);
            $task->addActivity('categories_created', "Created {$categoriesCount} ExamCategory records from structure_v2");

            // Update exam research_status
            $exam->research_status = 'completed';
            $exam->save();

            $task->addActivity('research_completed', 'Research pipeline completed successfully (parallel section generation)');

            // Finalize task
            $task->status = 'completed';
            $task->result = [
                'success' => true,
                'architecture' => 'v2_parallel',
                'structure_v2' => $structure,
                'sections_count' => $totalSections,
            ];
            $task->save();

            Log::info('✅ Research job completed successfully (parallel mode)', [
                'exam_id' => $exam->id,
                'task_id' => $task->id,
                'sections_count' => $totalSections,
                'categories_count' => ExamCategory::where('exam_id', $exam->id)->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Finalization failed', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $task->addActivity('finalization_exception', 'Finalization failed: ' . $e->getMessage());

            $task->status = 'failed';
            $task->error = 'Finalization failed: ' . $e->getMessage();
            $task->save();

            $exam->research_status = 'failed';
            $exam->save();

            throw $e;
        }
    }
}
