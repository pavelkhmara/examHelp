<?php

namespace App\Console\Commands;

use App\Models\Exam;
use App\Services\Golden\SnapshotManager;
use Illuminate\Console\Command;

class SnapshotCaptureCommand extends Command
{
    protected $signature = 'snapshot:capture
                            {exam : Exam UUID}
                            {--stage= : Stage to capture (identity, phase_a, phase_b, resolve_plans, synthesis)}
                            {--label= : Optional label for the snapshot}
                            {--all : Capture all stages}';

    protected $description = 'Capture current exam state as a snapshot';

    public function handle(SnapshotManager $manager): int
    {
        $examId = $this->argument('exam');
        $exam = Exam::find($examId);

        if (! $exam) {
            $this->error("Exam not found: {$examId}");

            return 1;
        }

        $this->info("Exam: {$exam->title}");
        $this->info("Status: {$exam->research_status}");
        $this->line('');

        $stages = $this->option('all')
            ? SnapshotManager::getAvailableStages()
            : [$this->option('stage') ?? 'synthesis'];

        $label = $this->option('label');

        foreach ($stages as $stage) {
            $this->info("Capturing {$stage}...");

            try {
                $snapshot = $manager->capture($exam, $stage, $label);
                $this->line("  ✓ Captured: {$snapshot->label} (hash: {$snapshot->getShortHash()})");
            } catch (\Exception $e) {
                $this->error("  ✗ Failed: {$e->getMessage()}");
            }
        }

        $this->line('');
        $this->info('Done!');

        return 0;
    }
}
