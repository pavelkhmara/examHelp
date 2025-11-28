<?php

namespace App\Console\Commands;

use App\Models\Exam;
use App\Services\Golden\GoldenLoader;
use App\Services\Golden\SnapshotManager;
use Illuminate\Console\Command;

class SnapshotCompareCommand extends Command
{
    protected $signature = 'snapshot:compare
                            {exam : Exam UUID}
                            {--stage=synthesis : Stage to compare}
                            {--baseline= : Specific baseline label to compare against}
                            {--golden= : Compare with golden fixture (exam ID in tests/Fixtures/stages/)}
                            {--threshold=0.8 : Similarity threshold (0-1)}
                            {--accept : Accept current state as new baseline if comparison passes}';

    protected $description = 'Compare current exam state with baseline snapshot or golden fixture';

    public function handle(SnapshotManager $manager, GoldenLoader $goldenLoader): int
    {
        $examId = $this->argument('exam');
        $exam = Exam::find($examId);

        if (! $exam) {
            $this->error("Exam not found: {$examId}");

            return 1;
        }

        $stage = $this->option('stage');
        $threshold = (float) $this->option('threshold');
        $goldenExamId = $this->option('golden');

        $this->info("Exam: {$exam->title}");
        $this->info("Stage: {$stage}");
        $this->line('');

        // Сравнение с golden fixture или snapshot
        if ($goldenExamId) {
            $comparison = $this->compareWithGolden($exam, $stage, $goldenExamId, $manager, $goldenLoader);
        } else {
            $baselineLabel = $this->option('baseline');
            $comparison = $manager->compare($exam, $stage, $baselineLabel);
        }

        $this->line($comparison->getReport());
        $this->line('');

        if (! $comparison->hasBaseline) {
            if ($this->confirm('No baseline found. Create one from current state?')) {
                $snapshot = $manager->accept($exam, $stage, 'baseline');
                $this->info("✓ Created baseline: {$snapshot->label}");
            }

            return 0;
        }

        if ($comparison->isPassing($threshold)) {
            $this->info('✓ Comparison passed!');

            if ($this->option('accept')) {
                $snapshot = $manager->accept($exam, $stage, 'baseline');
                $this->info("✓ Updated baseline: {$snapshot->label}");
            } elseif (! $goldenExamId && $this->confirm('Update baseline with current state?', false)) {
                $snapshot = $manager->accept($exam, $stage, 'baseline');
                $this->info("✓ Updated baseline: {$snapshot->label}");
            }

            return 0;
        }

        $this->warn("✗ Comparison failed (similarity {$comparison->similarity} below threshold {$threshold})");

        if (! $goldenExamId && $this->confirm('Accept current state as new baseline anyway?', false)) {
            $snapshot = $manager->accept($exam, $stage, 'baseline');
            $this->info("✓ Force-updated baseline: {$snapshot->label}");
        }

        return 1;
    }

    private function compareWithGolden(
        Exam $exam,
        string $stage,
        string $goldenExamId,
        SnapshotManager $manager,
        GoldenLoader $goldenLoader
    ): \App\Services\Golden\SnapshotComparison {
        $this->info("Comparing with golden fixture: {$goldenExamId}");

        if (! $goldenLoader->exists($goldenExamId)) {
            $this->error("Golden fixture not found: {$goldenExamId}");
            exit(1);
        }

        try {
            $golden = $goldenLoader->loadByStage($goldenExamId, $stage);

            return $manager->compareWithGolden($exam, $stage, $golden);
        } catch (\Exception $e) {
            $this->error("Failed to load golden fixture: {$e->getMessage()}");
            exit(1);
        }
    }
}
