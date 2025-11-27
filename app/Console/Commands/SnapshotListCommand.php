<?php

namespace App\Console\Commands;

use App\Services\Golden\GoldenLoader;
use App\Services\Golden\SnapshotManager;
use Illuminate\Console\Command;

class SnapshotListCommand extends Command
{
    protected $signature = 'snapshot:list
                            {exam? : Exam UUID (optional, lists all exams if not provided)}
                            {--stage= : Filter by stage}
                            {--golden : List golden fixtures instead of snapshots}';

    protected $description = 'List snapshots or golden fixtures';

    public function handle(SnapshotManager $manager, GoldenLoader $goldenLoader): int
    {
        if ($this->option('golden')) {
            return $this->listGoldenFixtures($goldenLoader);
        }

        $examId = $this->argument('exam');

        if (!$examId) {
            return $this->listAllExams($manager);
        }

        return $this->listExamSnapshots($manager, $examId);
    }

    private function listAllExams(SnapshotManager $manager): int
    {
        $exams = $manager->listExams();

        if (empty($exams)) {
            $this->info('No snapshots found.');
            $this->line('');
            $this->line('Use: php artisan snapshot:capture <exam-uuid> --all');

            return 0;
        }

        $this->info('Exams with snapshots:');
        $this->line('');

        $this->table(
            ['Exam ID', 'Stages', 'Snapshots'],
            array_map(fn ($e) => [
                substr($e['exam_id'], 0, 36),
                implode(', ', $e['stages']),
                $e['snapshots_count'],
            ], $exams)
        );

        return 0;
    }

    private function listExamSnapshots(SnapshotManager $manager, string $examId): int
    {
        $stage = $this->option('stage');
        $snapshots = $manager->list($examId, $stage);

        if (empty($snapshots)) {
            $this->info("No snapshots found for {$examId}");
            $this->line('');
            $this->line("Use: php artisan snapshot:capture {$examId} --all");

            return 0;
        }

        $this->info("Snapshots for: {$examId}");
        $this->line('');

        $this->table(
            ['Stage', 'Label', 'Captured At', 'Hash'],
            array_map(fn ($s) => [
                $s['stage'],
                $s['label'],
                $s['captured_at'] ?? '-',
                $s['hash'] ?? '-',
            ], $snapshots)
        );

        return 0;
    }

    private function listGoldenFixtures(GoldenLoader $goldenLoader): int
    {
        $exams = $goldenLoader->listExams();

        if (empty($exams)) {
            $this->info('No golden fixtures found.');
            $this->line('');
            $this->line('Golden fixtures location: tests/Fixtures/stages/');

            return 0;
        }

        $this->info('Golden Fixtures:');
        $this->line('');

        foreach ($exams as $exam) {
            $this->line("  {$exam['exam_id']}:");

            foreach ($exam['stages'] as $stage) {
                $this->line("    - {$stage['name']} ({$stage['filename']})");
            }

            $this->line('');
        }

        return 0;
    }
}
