<?php

namespace Tests\Feature\Golden;

use App\Models\Exam;
use App\Services\Golden\GoldenLoader;
use App\Services\Golden\StageComparator;

trait GoldenTestTrait
{
    protected GoldenLoader $goldenLoader;

    protected StageComparator $comparator;

    protected float $defaultThreshold = 0.85;

    protected function setUpGoldenTest(): void
    {
        $this->goldenLoader = app(GoldenLoader::class);
        $this->comparator = app(StageComparator::class);
    }

    /**
     * Создать экзамен из golden input fixture
     */
    protected function createExamFromGolden(string $goldenId): Exam
    {
        $input = $this->goldenLoader->load($goldenId, 0); // 00-input.json

        return Exam::factory()->create([
            'title' => $input['exam']['title'],
            'language_of_test' => $input['exam']['language_of_test'],
            'level' => $input['exam']['level'],
            'provider' => $input['exam']['provider'] ?? 'Test Provider',
            'family' => $input['exam']['family'] ?? 'Test Family',
            'description' => $input['exam']['description'] ?? '',
            'user_input' => $input['user_input'] ?? null,
        ]);
    }

    /**
     * Загрузить golden fixture для этапа
     */
    protected function loadGolden(string $goldenId, string $stage): array
    {
        return $this->goldenLoader->loadByStage($goldenId, $stage);
    }

    /**
     * Сравнить результат с golden fixture
     */
    protected function compareWithGolden(
        array $generated,
        array $golden,
        ?float $threshold = null
    ): \App\Services\Golden\ComparisonResult {
        return $this->comparator->compare($generated, $golden, [
            'threshold' => $threshold ?? $this->defaultThreshold,
        ]);
    }

    /**
     * Assert что similarity соответствует threshold
     */
    protected function assertSimilarToGolden(
        array $generated,
        array $golden,
        string $stageName,
        ?float $threshold = null
    ): void {
        $comparison = $this->compareWithGolden($generated, $golden, $threshold);

        $this->assertTrue(
            $comparison->passed,
            sprintf(
                "%s stage similarity %.1f%% is below threshold %.1f%%\n\nTop differences:\n%s",
                $stageName,
                $comparison->similarity * 100,
                ($threshold ?? $this->defaultThreshold) * 100,
                $this->formatDiffs($comparison->diffs, 5)
            )
        );
    }

    /**
     * Форматировать различия для вывода
     */
    protected function formatDiffs(array $diffs, int $limit = 10): string
    {
        if (empty($diffs)) {
            return '  (no differences)';
        }

        $lines = [];
        $shown = array_slice($diffs, 0, $limit);

        foreach ($shown as $diff) {
            $type = $diff['type'] ?? 'unknown';
            $path = $diff['path'] ?? '(unknown path)';

            $line = "  - [{$type}] {$path}";

            if (isset($diff['generated'])) {
                $genPreview = $this->valuePreview($diff['generated']);
                $line .= "\n    Generated: {$genPreview}";
            }

            if (isset($diff['golden'])) {
                $goldPreview = $this->valuePreview($diff['golden']);
                $line .= "\n    Golden: {$goldPreview}";
            }

            $lines[] = $line;
        }

        $remaining = count($diffs) - $limit;
        if ($remaining > 0) {
            $lines[] = "  ... and {$remaining} more differences";
        }

        return implode("\n", $lines);
    }

    /**
     * Превью значения для вывода
     */
    protected function valuePreview(mixed $value, int $maxLen = 80): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return 'Array('.count($value).')';
        }

        $str = (string) $value;
        if (strlen($str) > $maxLen) {
            return substr($str, 0, $maxLen).'...';
        }

        return $str;
    }

    /**
     * Получить список доступных golden fixtures
     */
    protected function getAvailableGoldenFixtures(): array
    {
        return array_column($this->goldenLoader->listExams(), 'exam_id');
    }

    /**
     * Provider для data-driven тестов
     */
    public static function goldenExamProvider(): array
    {
        $loader = app(GoldenLoader::class);
        $exams = $loader->listExams();

        $data = [];
        foreach ($exams as $exam) {
            $data[$exam['exam_id']] = [$exam['exam_id']];
        }

        return $data;
    }
}
