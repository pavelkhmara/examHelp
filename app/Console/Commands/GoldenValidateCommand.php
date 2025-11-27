<?php

namespace App\Console\Commands;

use App\Services\Golden\GoldenLoader;
use Illuminate\Console\Command;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

class GoldenValidateCommand extends Command
{
    protected $signature = 'golden:validate
                            {golden? : Golden fixture exam ID (e.g., pl-b1)}
                            {--all : Validate all golden fixtures}
                            {--stage= : Validate only specific stage}';

    protected $description = 'Validate golden fixtures against JSON schemas';

    public function handle(GoldenLoader $goldenLoader): int
    {
        $goldenExamId = $this->argument('golden');
        $validateAll = $this->option('all');

        if (!$goldenExamId && !$validateAll) {
            $this->error('Please specify a golden exam ID or use --all flag');

            return 1;
        }

        $examsToValidate = $validateAll
            ? array_column($goldenLoader->listExams(), 'exam_id')
            : [$goldenExamId];

        $this->info('Golden Fixtures Validation');
        $this->line(str_repeat('=', 50));
        $this->line('');

        $totalErrors = 0;
        $totalValidated = 0;

        foreach ($examsToValidate as $examId) {
            if (!$goldenLoader->exists($examId)) {
                $this->warn("Skipping {$examId}: not found");

                continue;
            }

            $this->line("Validating: {$examId}");

            $stages = $goldenLoader->listStages($examId);
            $stageFilter = $this->option('stage');

            foreach ($stages as $stageInfo) {
                $stageName = $stageInfo['name'];

                if ($stageFilter && $stageName !== $stageFilter) {
                    continue;
                }

                $totalValidated++;
                $errors = $this->validateStage($goldenLoader, $examId, $stageName, $stageInfo);

                if (empty($errors)) {
                    $this->line("  ✓ {$stageName}: Valid");
                } else {
                    $this->line("  ✗ {$stageName}: ".count($errors).' error(s)');
                    foreach (array_slice($errors, 0, 3) as $error) {
                        $this->line("      - {$error}");
                    }
                    if (count($errors) > 3) {
                        $this->line('      - ... and '.(count($errors) - 3).' more');
                    }
                    $totalErrors += count($errors);
                }
            }

            $this->line('');
        }

        $this->line(str_repeat('=', 50));

        if ($totalErrors === 0) {
            $this->info("✓ All {$totalValidated} fixtures are valid!");

            return 0;
        } else {
            $this->error("✗ Found {$totalErrors} error(s) in {$totalValidated} fixtures");

            return 1;
        }
    }

    private function validateStage(
        GoldenLoader $goldenLoader,
        string $examId,
        string $stageName,
        array $stageInfo
    ): array {
        try {
            // Загрузить fixture
            $data = $goldenLoader->loadByStage($examId, $stageName);

            // Загрузить schema
            $schemaFilename = str_replace('.json', '.schema.json', $stageInfo['filename']);
            $schemaPath = base_path("tests/Fixtures/stages/_schemas/{$schemaFilename}");
            if (!file_exists($schemaPath)) {
                return ["Schema file not found: {$schemaPath}"];
            }

            $schemaJson = file_get_contents($schemaPath);
            $schema = json_decode($schemaJson);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['Schema is not valid JSON: '.json_last_error_msg()];
            }

            // Валидировать
            $validator = new Validator;
            $result = $validator->validate(json_decode(json_encode($data)), $schema);

            if ($result->isValid()) {
                return [];
            }

            // Форматировать ошибки
            $formatter = new ErrorFormatter;
            $formattedError = $formatter->format($result->error(), false);

            return [json_encode($formattedError, JSON_UNESCAPED_SLASHES)];
        } catch (\Exception $e) {
            return ['Exception: '.$e->getMessage()];
        }
    }
}
