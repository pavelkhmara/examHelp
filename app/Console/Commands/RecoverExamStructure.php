<?php

namespace App\Console\Commands;

use App\Models\Exam;
use App\Services\LanguageApp\ExamStructureRecoveryService;
use Illuminate\Console\Command;

/**
 * Command to diagnose and recover missing exam_structure data
 *
 * Usage:
 * - Diagnose single exam: php artisan exam:recover-structure 123 --diagnose
 * - Recover single exam: php artisan exam:recover-structure 123
 * - Dry run: php artisan exam:recover-structure 123 --dry-run
 * - Recover all needing recovery: php artisan exam:recover-structure --all
 * - Scan all exams: php artisan exam:recover-structure --scan
 */
class RecoverExamStructure extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exam:recover-structure
                            {examId? : The exam ID to recover (optional if using --all or --scan)}
                            {--diagnose : Show diagnostics without making changes}
                            {--dry-run : Show what would be recovered without saving}
                            {--all : Recover all exams that need recovery}
                            {--scan : Scan all exams and show which need recovery}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose and recover missing exam_structure data from categories and example questions';

    /**
     * Execute the console command.
     */
    public function handle(ExamStructureRecoveryService $recovery): int
    {
        // Scan mode - check all exams
        if ($this->option('scan')) {
            return $this->scanAllExams($recovery);
        }

        // All mode - recover all exams that need it
        if ($this->option('all')) {
            return $this->recoverAllExams($recovery);
        }

        // Single exam mode
        $examId = $this->argument('examId');

        if (! $examId) {
            $this->error('Please provide an exam ID or use --all or --scan');

            return 1;
        }

        $exam = Exam::find($examId);

        if (! $exam) {
            $this->error("Exam {$examId} not found");

            return 1;
        }

        // Diagnose mode
        if ($this->option('diagnose')) {
            return $this->diagnoseExam($exam, $recovery);
        }

        // Recovery mode
        return $this->recoverExam($exam, $recovery);
    }

    /**
     * Diagnose a single exam
     */
    protected function diagnoseExam(Exam $exam, ExamStructureRecoveryService $recovery): int
    {
        $this->info("🔍 Diagnosing Exam #{$exam->id}: {$exam->title}");
        $this->newLine();

        $diagnostics = $recovery->diagnose($exam);

        // Current state
        $this->info('📊 Current State:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Has exam_structure', $diagnostics['current_state']['has_exam_structure'] ? '✓' : '✗'],
                ['question_archetypes', $diagnostics['current_state']['question_archetypes_count']],
                ['section_archetypes', $diagnostics['current_state']['section_archetypes_count']],
                ['Categories', $diagnostics['current_state']['categories_count']],
                ['Example Questions', $diagnostics['current_state']['example_questions_count']],
                ['Needs Recovery', $diagnostics['needs_recovery'] ? '⚠️ YES' : '✓ No'],
            ]
        );

        $this->newLine();

        // Category details
        if (! empty($diagnostics['categories'])) {
            $this->info('📂 Categories Details:');
            $this->table(
                ['Key', 'Name', 'Has Archetypes', 'Has Steps', 'Archetypes Count', 'Steps Count', 'Examples'],
                array_map(function ($cat) {
                    return [
                        $cat['key'],
                        $cat['name'],
                        $cat['has_question_archetypes'] ? '✓' : '✗',
                        $cat['has_steps'] ? '✓' : '✗',
                        $cat['question_archetypes_count'],
                        $cat['steps_count'],
                        $cat['example_questions_count'],
                    ];
                }, $diagnostics['categories'])
            );
        }

        $this->newLine();

        if ($diagnostics['needs_recovery']) {
            $this->warn('💡 Run without --diagnose to recover this exam');
        } else {
            $this->info('✅ This exam does not need recovery');
        }

        return 0;
    }

    /**
     * Recover a single exam
     */
    protected function recoverExam(Exam $exam, ExamStructureRecoveryService $recovery): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('🔄 Dry Run Mode - No changes will be saved');
        } else {
            $this->info('🔄 Recovering exam structure...');
        }

        $this->newLine();
        $this->info("Exam #{$exam->id}: {$exam->title}");

        $result = $recovery->recover($exam, $dryRun);

        $this->newLine();

        if ($result['needed_recovery']) {
            $this->info('✅ Recovery completed:');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Recovered Archetypes', $result['recovered_archetypes']],
                    ['Recovered Sections', $result['recovered_sections']],
                    ['Dry Run', $dryRun ? 'Yes (not saved)' : 'No (saved to DB)'],
                ]
            );

            if (! empty($result['errors'])) {
                $this->newLine();
                $this->error('⚠️ Errors:');
                foreach ($result['errors'] as $error) {
                    $this->line("  - {$error}");
                }
            }

            if (! $dryRun) {
                $this->newLine();
                $this->info('💾 Changes have been saved to the database');
            }
        } else {
            $this->info('ℹ️ No recovery needed - exam structure is already complete');
        }

        return 0;
    }

    /**
     * Scan all exams and show which need recovery
     */
    protected function scanAllExams(ExamStructureRecoveryService $recovery): int
    {
        $this->info('🔍 Scanning all exams...');
        $this->newLine();

        $exams = Exam::all();
        $needingRecovery = [];

        $progressBar = $this->output->createProgressBar($exams->count());
        $progressBar->start();

        foreach ($exams as $exam) {
            if ($recovery->needsRecovery($exam)) {
                $needingRecovery[] = $exam;
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        if (empty($needingRecovery)) {
            $this->info('✅ All exams have complete structures');

            return 0;
        }

        $count = count($needingRecovery);
        $this->warn("⚠️ Found {$count} exam(s) needing recovery:");
        $this->newLine();

        $this->table(
            ['ID', 'Title', 'Research Status', 'Categories'],
            array_map(function ($exam) {
                return [
                    $exam->id,
                    \Str::limit($exam->title, 50),
                    $exam->research_status,
                    $exam->categories()->count(),
                ];
            }, $needingRecovery)
        );

        $this->newLine();
        $this->info('💡 To recover all: php artisan exam:recover-structure --all');

        return 0;
    }

    /**
     * Recover all exams that need it
     */
    protected function recoverAllExams(ExamStructureRecoveryService $recovery): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('🔄 Dry Run Mode - No changes will be saved');
        } else {
            $this->info('🔄 Recovering all exams that need it...');
        }

        $this->newLine();

        $exams = Exam::all();
        $examIds = [];

        // Find exams needing recovery
        foreach ($exams as $exam) {
            if ($recovery->needsRecovery($exam)) {
                $examIds[] = $exam->id;
            }
        }

        if (empty($examIds)) {
            $this->info('✅ All exams have complete structures');

            return 0;
        }

        $count = count($examIds);
        $this->info("Found {$count} exam(s) needing recovery");
        $this->newLine();

        // Batch recover
        $results = $recovery->recoverBatch($examIds, $dryRun);

        // Display results
        $this->info('📊 Recovery Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total', $results['total']],
                ['Recovered', $results['recovered']],
                ['Skipped', $results['skipped']],
                ['Failed', $results['failed']],
            ]
        );

        if ($results['failed'] > 0) {
            $this->newLine();
            $this->error('⚠️ Some recoveries failed:');
            foreach ($results['details'] as $examId => $detail) {
                if (isset($detail['error'])) {
                    $this->line("  Exam #{$examId}: {$detail['error']}");
                }
            }
        }

        if (! $dryRun && $results['recovered'] > 0) {
            $this->newLine();
            $this->info('💾 Changes have been saved to the database');
        }

        return 0;
    }
}
