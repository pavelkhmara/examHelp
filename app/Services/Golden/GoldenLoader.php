<?php

namespace App\Services\Golden;

class GoldenLoader
{
    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? base_path('tests/Fixtures/stages');
    }

    /**
     * Загрузить эталон для конкретного этапа
     */
    public function load(string $examId, int|string $stage): array
    {
        $filename = $this->getStageFilename($stage);
        $path = "{$this->basePath}/{$examId}/{$filename}";

        if (! file_exists($path)) {
            throw new \RuntimeException("Golden fixture not found: {$path}");
        }

        $content = file_get_contents($path);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in golden fixture: {$path}");
        }

        return $data;
    }

    /**
     * Загрузить эталон по имени этапа
     */
    public function loadByStage(string $examId, string $stageName): array
    {
        $stageNumber = $this->getStageNumber($stageName);

        return $this->load($examId, $stageNumber);
    }

    /**
     * Проверить, существуют ли эталоны для экзамена
     */
    public function exists(string $examId): bool
    {
        return is_dir("{$this->basePath}/{$examId}");
    }

    /**
     * Проверить, существует ли эталон для конкретного этапа
     */
    public function stageExists(string $examId, int|string $stage): bool
    {
        $filename = $this->getStageFilename($stage);

        return file_exists("{$this->basePath}/{$examId}/{$filename}");
    }

    /**
     * Получить список экзаменов с эталонами
     */
    public function listExams(): array
    {
        $exams = [];
        $dirs = glob("{$this->basePath}/*", GLOB_ONLYDIR);

        foreach ($dirs as $dir) {
            $examId = basename($dir);
            if ($examId === '_schemas') {
                continue;
            }

            $stages = $this->listStages($examId);

            $exams[] = [
                'exam_id' => $examId,
                'stages' => $stages,
                'stages_count' => count($stages),
            ];
        }

        return $exams;
    }

    /**
     * Получить список этапов для экзамена
     */
    public function listStages(string $examId): array
    {
        $stages = [];
        $stageFiles = [
            0 => '00-input.json',
            1 => '01-identity.json',
            2 => '02-phase-a.json',
            3 => '03-phase-b.json',
            4 => '04-resolve-plans.json',
            5 => '05-synthesis.json',
        ];

        foreach ($stageFiles as $num => $filename) {
            $path = "{$this->basePath}/{$examId}/{$filename}";
            if (file_exists($path)) {
                $stages[] = [
                    'number' => $num,
                    'name' => $this->getStageName($num),
                    'filename' => $filename,
                ];
            }
        }

        return $stages;
    }

    /**
     * Загрузить все этапы для экзамена
     */
    public function loadAll(string $examId): array
    {
        $stages = [];
        for ($i = 0; $i <= 5; $i++) {
            try {
                $stages[$i] = $this->load($examId, $i);
            } catch (\RuntimeException $e) {
                // Этап может отсутствовать
                $stages[$i] = null;
            }
        }

        return $stages;
    }

    /**
     * Загрузить финальный эталон из exams/ директории
     */
    public function loadFinalExam(string $examId): ?array
    {
        $path = base_path("tests/Fixtures/exams/{$examId}.json");

        if (! file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);

        return json_decode($content, true);
    }

    private function getStageFilename(int|string $stage): string
    {
        if (is_string($stage)) {
            $stage = $this->getStageNumber($stage);
        }

        $stageFiles = [
            0 => '00-input.json',
            1 => '01-identity.json',
            2 => '02-phase-a.json',
            3 => '03-phase-b.json',
            4 => '04-resolve-plans.json',
            5 => '05-synthesis.json',
        ];

        return $stageFiles[$stage] ?? throw new \InvalidArgumentException("Unknown stage: {$stage}");
    }

    private function getStageNumber(string $stageName): int
    {
        $mapping = [
            'input' => 0,
            'identity' => 1,
            'phase_a' => 2,
            'phase-a' => 2,
            'phaseA' => 2,
            'phase_b' => 3,
            'phase-b' => 3,
            'phaseB' => 3,
            'resolve_plans' => 4,
            'resolve-plans' => 4,
            'resolvePlans' => 4,
            'synthesis' => 5,
        ];

        return $mapping[$stageName] ?? throw new \InvalidArgumentException("Unknown stage name: {$stageName}");
    }

    private function getStageName(int $stageNumber): string
    {
        $names = [
            0 => 'input',
            1 => 'identity',
            2 => 'phase_a',
            3 => 'phase_b',
            4 => 'resolve_plans',
            5 => 'synthesis',
        ];

        return $names[$stageNumber] ?? 'unknown';
    }

    /**
     * Получить базовый путь
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * Список доступных golden fixtures (для UI)
     */
    public function listFixtures(): array
    {
        $fixtures = [];
        $dirs = glob("{$this->basePath}/*", GLOB_ONLYDIR);

        foreach ($dirs as $dir) {
            $fixtureId = basename($dir);
            if ($fixtureId === '_schemas') {
                continue;
            }

            $stages = $this->getAvailableStages($fixtureId);

            $fixtures[] = [
                'id' => $fixtureId,
                'stages' => $stages,
                'stages_count' => count($stages),
            ];
        }

        return $fixtures;
    }

    /**
     * Получить список доступных этапов для fixture (по именам)
     */
    public function getAvailableStages(string $fixtureId): array
    {
        $stages = [];
        $stageFiles = [
            'identity' => '01-identity.json',
            'phase_a' => '02-phase-a.json',
            'phase_b' => '03-phase-b.json',
            'resolve_plans' => '04-resolve-plans.json',
            'synthesis' => '05-synthesis.json',
        ];

        foreach ($stageFiles as $stageName => $filename) {
            $path = "{$this->basePath}/{$fixtureId}/{$filename}";
            if (file_exists($path)) {
                $stages[] = $stageName;
            }
        }

        return $stages;
    }

    /**
     * Загрузить golden для конкретного stage по имени
     */
    public function loadStage(string $fixtureId, string $stageName): ?array
    {
        $stageFiles = [
            'input' => '00-input.json',
            'identity' => '01-identity.json',
            'phase_a' => '02-phase-a.json',
            'phase_b' => '03-phase-b.json',
            'resolve_plans' => '04-resolve-plans.json',
            'synthesis' => '05-synthesis.json',
        ];

        if (! isset($stageFiles[$stageName])) {
            return null;
        }

        $path = "{$this->basePath}/{$fixtureId}/{$stageFiles[$stageName]}";

        if (! file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $data;
    }
}
