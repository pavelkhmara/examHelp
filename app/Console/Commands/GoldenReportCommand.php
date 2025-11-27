<?php

namespace App\Console\Commands;

use App\Models\Exam;
use App\Services\Golden\GoldenLoader;
use App\Services\Golden\SnapshotManager;
use App\Services\Golden\StageComparator;
use Illuminate\Console\Command;

class GoldenReportCommand extends Command
{
    protected $signature = 'golden:report
                            {golden : Golden fixture exam ID (e.g., pl-b1)}
                            {--exam= : Compare with specific exam UUID}
                            {--stage= : Compare only specific stage}
                            {--json : Output as JSON}';

    protected $description = 'Generate comparison report between exam and golden fixtures';

    public function handle(
        GoldenLoader $goldenLoader,
        SnapshotManager $snapshotManager,
        StageComparator $comparator
    ): int {
        $goldenExamId = $this->argument('golden');

        if (! $goldenLoader->exists($goldenExamId)) {
            $this->error("Golden fixtures not found for: {$goldenExamId}");
            $this->line('');
            $this->line('Available golden fixtures:');
            foreach ($goldenLoader->listExams() as $exam) {
                $this->line("  - {$exam['exam_id']}");
            }

            return 1;
        }

        $this->info("Golden Fixtures Report: {$goldenExamId}");
        $this->line(str_repeat('=', 50));

        // Список этапов
        $stages = $goldenLoader->listStages($goldenExamId);
        $this->line('');
        $this->info('Available stages:');

        foreach ($stages as $stage) {
            $this->line("  ✓ {$stage['number']}: {$stage['name']} ({$stage['filename']})");
        }

        // Если указан exam — сравнить
        $examUuid = $this->option('exam');
        if ($examUuid) {
            $this->line('');
            $this->compareWithExam($examUuid, $goldenExamId, $goldenLoader, $snapshotManager, $comparator);
        }

        return 0;
    }

    private function compareWithExam(
        string $examUuid,
        string $goldenExamId,
        GoldenLoader $goldenLoader,
        SnapshotManager $snapshotManager,
        StageComparator $comparator
    ): void {
        $exam = Exam::find($examUuid);
        if (! $exam) {
            $this->error("Exam not found: {$examUuid}");

            return;
        }

        $this->info("Comparing with: {$exam->title}");
        $this->line(str_repeat('-', 50));

        $stageFilter = $this->option('stage');
        $stages = ['identity', 'phase_a', 'phase_b', 'resolve_plans', 'synthesis'];

        if ($stageFilter) {
            $stages = [$stageFilter];
        }

        $results = [];

        foreach ($stages as $stageName) {
            if (! $goldenLoader->stageExists($goldenExamId, $stageName)) {
                $this->line("  - {$stageName}: No golden fixture");

                continue;
            }

            try {
                $golden = $goldenLoader->loadByStage($goldenExamId, $stageName);
                $comparison = $snapshotManager->compareWithGolden($exam, $stageName, $golden);

                $pct = round($comparison->similarity * 100, 1);
                $status = $comparison->isPassing() ? '✓' : '✗';
                $diffsCount = count($comparison->diffs);

                $this->line("  {$status} {$stageName}: {$pct}% ({$diffsCount} diffs)");

                $results[$stageName] = [
                    'similarity' => $comparison->similarity,
                    'passed' => $comparison->isPassing(),
                    'diffs_count' => $diffsCount,
                ];

                // Показать топ различий если не проходит
                if (! $comparison->isPassing() && $diffsCount > 0) {
                    foreach (array_slice($comparison->diffs, 0, 3) as $diff) {
                        $this->line("      - [{$diff['type']}] {$diff['path']}");
                    }
                }
            } catch (\Exception $e) {
                $this->line("  ✗ {$stageName}: Error - {$e->getMessage()}");
            }
        }

        if ($this->option('json')) {
            $this->line('');
            $this->line(json_encode($results, JSON_PRETTY_PRINT));
        }
    }
}
