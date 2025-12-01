<?php

namespace App\Jobs;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\GenerationTask;
use App\Services\LanguageApp\AssemblyResolver;
use App\Services\LanguageApp\ExamResearchService;
use App\Services\LanguageApp\SanityCheckerService;
use App\Services\LanguageApp\StructureMaterializer;
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

    public function handle(
        ExamResearchService $svc,
        SanityCheckerService $sanityChecker,
        AssemblyResolver $assemblyResolver
    ): void {
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

            if (! empty($sections) && $identitySnapshot) {
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
                        ['Low compliance with exam family invariants: '.$complianceScore]
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

            // Generate example questions (skip if skip_examples flag is set)
            $skipExamples = (bool) ($task->request['skip_examples'] ?? false);

            if ($skipExamples) {
                $task->addActivity('example_generation_skipped', 'Example generation skipped (skip_examples=true)');
                Log::info('Skipping example generation in FinalizeSectionGenerationJob', [
                    'exam_id' => $exam->id,
                    'task_id' => $task->id,
                    'reason' => 'skip_examples flag set',
                ]);
            } else {
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

                    $task->addActivity('example_generation_failed', 'Example generation failed: '.$e->getMessage());

                    $result = (array) ($task->result ?? []);
                    $result['examples_error'] = $e->getMessage();
                    $task->result = $result;
                    $task->save();
                }
            }

            // Materialize structure_v2 into database tables (ExamCategory, QuestionGroup, Question)
            // IMPORTANT: Must be done BEFORE resolving plans (plans need ExamCategory to exist) (ExamCategory, QuestionGroup, Question)
            $task->addActivity('materializing_structure', 'Materializing structure_v2 into database tables');

            $materializer = new StructureMaterializer;
            $stats = $materializer->materialize($exam, $sections);

            $task->addActivity('structure_materialized', sprintf(
                'Materialized: %d categories, %d question groups, %d questions',
                $stats['categories'],
                $stats['question_groups'],
                $stats['questions']
            ));

            // Resolve generation plans from assembly configuration
            // IMPORTANT: Must be done AFTER materializing (needs ExamCategory records)
            $task->addActivity('resolve_plans_started', 'Resolving generation plans from assembly configuration');
            $task->updateHeartbeat();

            try {
                $plans = $assemblyResolver->resolve($exam);
                $plansCount = count($plans);

                $task->addActivity('resolve_plans_completed', "Resolved {$plansCount} generation plans", [
                    'plans_count' => $plansCount,
                    'plans' => collect($plans)->map(fn ($p) => [
                        'id' => $p->id,
                        'section_id' => $p->section_id,
                        'assembly_mode' => $p->assembly_mode,
                        'total_questions' => $p->total_questions,
                    ])->toArray(),
                ]);

                Log::info('[FinalizeSectionGenerationJob] Generation plans resolved', [
                    'exam_id' => $exam->id,
                    'task_id' => $task->id,
                    'plans_count' => $plansCount,
                ]);
            } catch (\Throwable $e) {
                Log::error('[FinalizeSectionGenerationJob] Plan resolution failed', [
                    'exam_id' => $exam->id,
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);

                $task->addActivity('resolve_plans_failed', 'Plan resolution failed: '.$e->getMessage());

                // Don't fail the whole job - plans are resolved on-demand later
                // Store error in task result for visibility
                $result = (array) ($task->result ?? []);
                $result['plan_resolution_error'] = $e->getMessage();
                $task->result = $result;
                $task->save();
            }

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

            $task->addActivity('finalization_exception', 'Finalization failed: '.$e->getMessage());

            $task->status = 'failed';
            $task->error = 'Finalization failed: '.$e->getMessage();
            $task->save();

            $exam->research_status = 'failed';
            $exam->save();

            throw $e;
        }
    }
}
