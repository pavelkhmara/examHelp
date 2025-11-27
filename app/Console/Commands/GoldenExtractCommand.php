<?php

namespace App\Console\Commands;

use App\Models\Exam;
use App\Services\Golden\SnapshotManager;
use Illuminate\Console\Command;

class GoldenExtractCommand extends Command
{
    protected $signature = 'golden:extract
                            {exam : Exam UUID to extract from}
                            {--output= : Output directory (default: tests/Fixtures/stages/{exam-slug})}
                            {--exam-id= : Golden exam ID (default: auto-detected from title)}
                            {--force : Overwrite existing files}';

    protected $description = 'Extract golden stage fixtures from an exam';

    public function handle(SnapshotManager $snapshotManager): int
    {
        $examUuid = $this->argument('exam');

        $exam = Exam::find($examUuid);
        if (! $exam) {
            $this->error("Exam not found: {$examUuid}");

            return 1;
        }

        $this->info("Extracting golden fixtures from: {$exam->title}");
        $this->line("UUID: {$exam->id}");
        $this->line(str_repeat('=', 50));

        // Определить exam_id для golden fixtures
        $examId = $this->option('exam-id') ?? $this->detectExamId($exam);
        $this->line("Golden Exam ID: {$examId}");

        // Определить output директорию
        $outputDir = $this->option('output') ?? base_path("tests/Fixtures/stages/{$examId}");

        if (file_exists($outputDir) && ! $this->option('force')) {
            $this->warn("Output directory already exists: {$outputDir}");
            if (! $this->confirm('Overwrite existing files?')) {
                $this->line('Cancelled.');

                return 0;
            }
        }

        // Создать директорию
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $this->line('');
        $this->info('Extracting stages:');

        // 00-input.json
        $this->extractInput($exam, $examId, $outputDir);

        // 01-identity.json
        $this->extractStage($exam, $examId, 'identity', '01-identity.json', $outputDir, $snapshotManager);

        // 02-phase-a.json
        $this->extractStage($exam, $examId, 'phase_a', '02-phase-a.json', $outputDir, $snapshotManager);

        // 03-phase-b.json
        $this->extractStage($exam, $examId, 'phase_b', '03-phase-b.json', $outputDir, $snapshotManager);

        // 04-resolve-plans.json
        $this->extractStage($exam, $examId, 'resolve_plans', '04-resolve-plans.json', $outputDir, $snapshotManager);

        // 05-synthesis.json
        $this->extractStage($exam, $examId, 'synthesis', '05-synthesis.json', $outputDir, $snapshotManager);

        $this->line('');
        $this->info('✓ Extraction complete!');
        $this->line("Output: {$outputDir}");
        $this->line('');
        $this->line('Next steps:');
        $this->line("  1. Review extracted files in {$outputDir}");
        $this->line("  2. Run: php artisan golden:report {$examId} --exam={$exam->id}");
        $this->line('  3. Check similarity score (target: ≥85%)');

        return 0;
    }

    private function detectExamId(Exam $exam): string
    {
        // Попытка определить exam_id из названия
        // Примеры: "Polish B1" -> "pl-b1", "DELF B1" -> "delf-b1"

        $title = strtolower($exam->title);

        // Polish
        if (str_contains($title, 'polish') || str_contains($title, 'polski')) {
            $level = strtolower($exam->level);

            return "pl-{$level}";
        }

        // DELF/DALF
        if (str_contains($title, 'delf') || str_contains($title, 'dalf')) {
            $level = strtolower($exam->level);

            return "delf-{$level}";
        }

        // Goethe
        if (str_contains($title, 'goethe')) {
            $level = strtolower($exam->level);

            return "goethe-{$level}";
        }

        // IELTS
        if (str_contains($title, 'ielts')) {
            $type = str_contains($title, 'academic') ? 'academic' : 'general';

            return "ielts-{$type}";
        }

        // Fallback: exam UUID
        return substr($exam->id, 0, 8);
    }

    private function extractInput(Exam $exam, string $examId, string $outputDir): void
    {
        $data = [
            '$schema' => '../_schemas/00-input.schema.json',
            'version' => '1.0',
            'exam_id' => $examId,
            'created_at' => now()->format('Y-m-d'),

            'exam' => [
                'title' => $exam->title,
                'language_of_test' => $exam->language_of_test,
                'level' => $exam->level,
                'provider' => $exam->provider,
                'family' => $exam->family,
                'description' => $exam->description,
            ],

            'user_input' => $exam->user_input,

            'documents' => $exam->documents->map(fn ($doc) => [
                'filename' => $doc->filename,
                'extracted_text_preview' => substr($doc->extracted_text ?? '', 0, 500),
                'pages' => $doc->meta['pages'] ?? null,
            ])->toArray(),

            '_notes' => 'Extracted from exam: '.$exam->id,
        ];

        $filename = '00-input.json';
        $this->saveJson($data, $outputDir.'/'.$filename);
        $this->line("  ✓ {$filename}");
    }

    private function extractStage(
        Exam $exam,
        string $examId,
        string $stage,
        string $filename,
        string $outputDir,
        SnapshotManager $snapshotManager
    ): void {
        try {
            $data = $snapshotManager->extractStageData($exam, $stage);

            // Добавить мета-поля
            $data['$schema'] = "../_schemas/{$filename}";
            $data['version'] = '1.0';
            $data['exam_id'] = $examId;

            // Добавить comparison hints
            $data['_comparison_hints'] = $this->getComparisonHints($stage);

            // Добавить notes
            $data['_notes'] = "Extracted from exam: {$exam->id} ({$exam->title})";

            $this->saveJson($data, $outputDir.'/'.$filename);
            $this->line("  ✓ {$filename}");
        } catch (\Exception $e) {
            $this->line("  ✗ {$filename}: {$e->getMessage()}");
        }
    }

    private function getComparisonHints(string $stage): array
    {
        return match ($stage) {
            'identity' => [
                'critical_fields' => [
                    'identity_data.confirmed_title',
                    'identity_data.confirmed_provider',
                    'identity_data.confirmed_level',
                ],
                'flexible_fields' => [
                    'identity_data.family',
                    'identity_data.description',
                    'confidence.overall',
                ],
                'ignore_fields' => [
                    'sources',
                    'verification_method',
                    'iterations_count',
                ],
            ],

            'phase_a' => [
                'critical_fields' => [
                    'structure_v2.sections[].skill',
                    'structure_v2.sections[].duration_min',
                    'structure_v2.sections[].max_score',
                    'structure_v2.sections[].min_pass_percent',
                    'structure_v2.pass_policy.mode',
                ],
                'flexible_fields' => [
                    'structure_v2.sections[].description',
                    'structure_v2.sections[].expected_task_count',
                    'structure_v2.meta.tags',
                ],
                'ignore_fields' => [
                    'structure_v2.id',
                    'structure_v2.version',
                ],
            ],

            'phase_b' => [
                'critical_fields' => [
                    'structure_v2.sections[].assembly.mode',
                    'structure_v2.sections[].assembly.question_groups[].questions',
                    'structure_v2.sections[].assembly.question_groups[].playback_settings.max_plays',
                ],
                'flexible_fields' => [
                    'structure_v2.sections[].assembly.question_groups[].instructions',
                    'structure_v2.sections[].assembly.question_groups[].stimulus',
                ],
                'ignore_fields' => [],
            ],

            'resolve_plans' => [
                'critical_fields' => [
                    'generation_plans[].section_id',
                    'generation_plans[].questions_count',
                    'generation_plans[].question_type',
                    'summary.total_plans',
                ],
                'flexible_fields' => [
                    'summary.by_section',
                ],
                'ignore_fields' => [],
            ],

            'synthesis' => [
                'critical_fields' => [
                    'sections[].skill',
                    'sections[].question_groups[].group_id',
                    'sections[].question_groups[].questions[].type',
                    'sections[].question_groups[].questions[].interaction.response_type',
                ],
                'flexible_fields' => [
                    'sections[].question_groups[].questions[].interaction.stem',
                    'sections[].question_groups[].questions[].instructions',
                ],
                'ignore_fields' => [
                    'sections[].question_groups[].questions[].id',
                    'sections[].question_groups[].questions[].created_at',
                ],
            ],

            default => [
                'critical_fields' => [],
                'flexible_fields' => [],
                'ignore_fields' => [],
            ],
        };
    }

    private function saveJson(array $data, string $path): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $json);
    }
}
