<?php

namespace App\Services\Golden;

use App\Models\Exam;
use Illuminate\Support\Facades\Log;

class SnapshotManager
{
    private string $basePath;

    private StageComparator $comparator;

    public function __construct(?string $basePath = null, ?StageComparator $comparator = null)
    {
        $this->basePath = $basePath ?? storage_path('snapshots/exams');
        $this->comparator = $comparator ?? new StageComparator;
    }

    /**
     * Создать snapshot текущего состояния экзамена
     */
    public function capture(Exam $exam, string $stage, ?string $label = null): Snapshot
    {
        $data = $this->extractStageData($exam, $stage);
        $hash = $this->computeHash($data);
        $timestamp = now()->format('Y-m-d_H-i-s');
        $label = $label ?? $timestamp;

        $snapshot = new Snapshot(
            examId: $exam->id,
            stage: $stage,
            label: $label,
            hash: $hash,
            data: $data,
            capturedAt: now(),
            metadata: [
                'exam_title' => $exam->title,
                'exam_level' => $exam->level,
                'research_status' => $exam->research_status,
            ]
        );

        $this->save($snapshot);

        Log::info('Snapshot captured', [
            'exam_id' => $exam->id,
            'stage' => $stage,
            'label' => $label,
            'hash' => $snapshot->getShortHash(),
        ]);

        return $snapshot;
    }

    /**
     * Сравнить текущее состояние с snapshot
     */
    public function compare(Exam $exam, string $stage, ?string $snapshotLabel = null): SnapshotComparison
    {
        $current = $this->extractStageData($exam, $stage);

        // Загрузить snapshot (последний или по label)
        $snapshot = $snapshotLabel
            ? $this->load($exam->id, $stage, $snapshotLabel)
            : $this->loadLatest($exam->id, $stage);

        if (! $snapshot) {
            return new SnapshotComparison(
                hasBaseline: false,
                current: $current,
                baseline: null,
                similarity: 0,
                diffs: [],
                message: 'No baseline snapshot found. Run capture first.'
            );
        }

        $result = $this->comparator->compare($current, $snapshot->data);

        return new SnapshotComparison(
            hasBaseline: true,
            current: $current,
            baseline: $snapshot,
            similarity: $result->similarity,
            diffs: $result->diffs,
            message: $result->getSummary()
        );
    }

    /**
     * Сравнить с golden fixture
     */
    public function compareWithGolden(Exam $exam, string $stage, array $golden): SnapshotComparison
    {
        $current = $this->extractStageData($exam, $stage);
        $result = $this->comparator->compare($current, $golden);

        $goldenSnapshot = new Snapshot(
            examId: $golden['exam_id'] ?? 'golden',
            stage: $stage,
            label: 'golden',
            hash: $this->computeHash($golden),
            data: $golden,
            capturedAt: now(),
            metadata: ['type' => 'golden_fixture']
        );

        return new SnapshotComparison(
            hasBaseline: true,
            current: $current,
            baseline: $goldenSnapshot,
            similarity: $result->similarity,
            diffs: $result->diffs,
            message: $result->getSummary()
        );
    }

    /**
     * Принять текущее состояние как новый baseline
     */
    public function accept(Exam $exam, string $stage, string $label = 'baseline'): Snapshot
    {
        // Архивировать старый baseline
        $existing = $this->load($exam->id, $stage, $label);
        if ($existing) {
            $this->archive($existing);
        }

        // Создать новый snapshot с label
        return $this->capture($exam, $stage, $label);
    }

    /**
     * Извлечь данные этапа из экзамена
     */
    public function extractStageData(Exam $exam, string $stage): array
    {
        return match ($stage) {
            'identity' => $this->extractIdentity($exam),
            'phase_a' => $this->extractPhaseA($exam),
            'phase_b' => $this->extractPhaseB($exam),
            'resolve_plans' => $this->extractResolvePlans($exam),
            'synthesis' => $this->extractSynthesis($exam),
            default => throw new \InvalidArgumentException("Unknown stage: {$stage}"),
        };
    }

    private function extractIdentity(Exam $exam): array
    {
        $identity = $exam->confirmedIdentity;

        return [
            'stage' => 'identity',
            'exam_id' => $exam->id,
            'identity_data' => $identity?->identity_data ?? $exam->meta['identity_data'] ?? null,
            'confidence' => [
                'overall' => $identity?->identity_data['confidence'] ?? null,
            ],
        ];
    }

    private function extractPhaseA(Exam $exam): array
    {
        $structure = $exam->meta['structure_v2'] ?? [];

        // Убираем assembly из секций для Phase A
        $sections = [];
        foreach ($structure['sections'] ?? [] as $section) {
            $cleanSection = $section;
            unset($cleanSection['assembly'], $cleanSection['tasks'], $cleanSection['question_groups']);
            $sections[] = $cleanSection;
        }

        return [
            'stage' => 'phase_a',
            'exam_id' => $exam->id,
            'structure_v2' => [
                'id' => $structure['id'] ?? null,
                'version' => $structure['version'] ?? '2.0',
                'meta' => $structure['meta'] ?? null,
                'pass_policy' => $structure['pass_policy'] ?? null,
                'policies' => $structure['policies'] ?? null,
                'sections' => $sections,
            ],
        ];
    }

    private function extractPhaseB(Exam $exam): array
    {
        $structure = $exam->meta['structure_v2'] ?? [];

        return [
            'stage' => 'phase_b',
            'exam_id' => $exam->id,
            'structure_v2' => $structure,
        ];
    }

    private function extractResolvePlans(Exam $exam): array
    {
        // GenerationPlan модель может не существовать - извлекаем из meta или tasks
        $structure = $exam->meta['structure_v2'] ?? [];
        $plans = [];

        foreach ($structure['sections'] ?? [] as $section) {
            $sectionId = $section['id'] ?? 'unknown';
            $assembly = $section['assembly'] ?? [];

            // Извлекаем планы из question_groups
            foreach ($assembly['question_groups'] ?? [] as $group) {
                $plans[] = [
                    'section_id' => $sectionId,
                    'group_id' => $group['id'] ?? null,
                    'questions_count' => count($group['questions'] ?? []),
                    'question_type' => $group['questions'][0]['type'] ?? null,
                    'synthesis_mode' => $assembly['mode'] ?? 'inline',
                ];
            }

            // Извлекаем планы из tasks
            foreach ($assembly['tasks'] ?? [] as $task) {
                $plans[] = [
                    'section_id' => $sectionId,
                    'task_id' => $task['id'] ?? null,
                    'questions_count' => 1,
                    'question_type' => $task['type'] ?? null,
                    'synthesis_mode' => $assembly['mode'] ?? 'inline',
                ];
            }
        }

        // Группировка по секциям
        /** @var array<string, array{plans: int, questions: int}> $bySection */
        $bySection = [];
        foreach ($plans as $plan) {
            /** @var string $sectionId */
            $sectionId = $plan['section_id'];
            if (! isset($bySection[$sectionId])) {
                $bySection[$sectionId] = ['plans' => 0, 'questions' => 0];
            }
            $bySection[$sectionId]['plans']++;
            /** @var int $questionsCount */
            $questionsCount = $plan['questions_count'];
            $bySection[$sectionId]['questions'] += $questionsCount;
        }

        return [
            'stage' => 'resolve_plans',
            'exam_id' => $exam->id,
            'generation_plans' => $plans,
            'summary' => [
                'total_plans' => count($plans),
                'by_section' => $bySection,
            ],
        ];
    }

    private function extractSynthesis(Exam $exam): array
    {
        $sections = [];

        // Используем categories() relationship
        foreach ($exam->categories()->get() as $category) {
            $section = [
                'key' => $category->slug ?? $category->id,
                'skill' => $category->skill ?? $category->meta['skill'] ?? null,
                'name' => $category->name,
                'question_groups' => [],
            ];

            // Загружаем question groups для этой категории
            $questionGroups = $exam->questionGroups()
                ->where('section_id', $category->id)
                ->orderBy('order')
                ->get();

            foreach ($questionGroups as $group) {
                $groupData = [
                    'group_id' => $group->group_id,
                    'title' => $group->title,
                    'order' => $group->order,
                    'questions' => [],
                ];

                // Загружаем вопросы группы
                $questions = $exam->questions()
                    ->where('question_group_id', $group->id)
                    ->orderBy('order')
                    ->get();

                foreach ($questions as $question) {
                    $groupData['questions'][] = [
                        'question_id' => $question->question_id ?? $question->id,
                        'type' => $question->type,
                        'order' => $question->order,
                        'instructions' => $question->instructions,
                        'interaction' => $question->interaction,
                        'scoring' => $question->scoring,
                    ];
                }

                $section['question_groups'][] = $groupData;
            }

            // Также добавляем ungrouped questions
            $ungroupedQuestions = $exam->questions()
                ->where('section_id', $category->id)
                ->whereNull('question_group_id')
                ->orderBy('order')
                ->get();

            if ($ungroupedQuestions->isNotEmpty()) {
                $section['ungrouped_questions'] = $ungroupedQuestions->map(fn ($q) => [
                    'question_id' => $q->question_id ?? $q->id,
                    'type' => $q->type,
                    'order' => $q->order,
                ])->toArray();
            }

            $sections[] = $section;
        }

        return [
            'stage' => 'synthesis',
            'exam_id' => $exam->id,
            'sections' => $sections,
        ];
    }

    private function computeHash(array $data): string
    {
        // Убираем метаполя перед хешированием
        $cleanData = $data;
        unset($cleanData['_comparison_hints'], $cleanData['_notes'], $cleanData['_reference']);

        $normalized = json_encode($cleanData, JSON_UNESCAPED_UNICODE);

        return hash('sha256', $normalized);
    }

    private function save(Snapshot $snapshot): void
    {
        $path = $this->getSnapshotPath($snapshot->examId, $snapshot->stage, $snapshot->label);
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode($snapshot->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function load(string $examId, string $stage, string $label): ?Snapshot
    {
        $path = $this->getSnapshotPath($examId, $stage, $label);

        if (! file_exists($path)) {
            return null;
        }

        $data = json_decode(file_get_contents($path), true);

        return Snapshot::fromArray($data);
    }

    public function loadLatest(string $examId, string $stage): ?Snapshot
    {
        // Сначала пробуем baseline
        $baseline = $this->load($examId, $stage, 'baseline');
        if ($baseline) {
            return $baseline;
        }

        // Иначе ищем последний по дате
        $dir = "{$this->basePath}/{$examId}/{$stage}";
        if (! is_dir($dir)) {
            return null;
        }

        $files = glob("{$dir}/*.json");
        if (empty($files)) {
            return null;
        }

        // Сортируем по времени модификации (новые первые)
        usort($files, fn ($a, $b) => filemtime($b) - filemtime($a));

        $data = json_decode(file_get_contents($files[0]), true);

        return Snapshot::fromArray($data);
    }

    private function archive(Snapshot $snapshot): void
    {
        $oldPath = $this->getSnapshotPath($snapshot->examId, $snapshot->stage, $snapshot->label);
        $archiveLabel = "archive_{$snapshot->label}_{$snapshot->capturedAt->format('Ymd_His')}";
        $archivePath = $this->getSnapshotPath($snapshot->examId, $snapshot->stage, $archiveLabel);

        if (file_exists($oldPath)) {
            rename($oldPath, $archivePath);
        }
    }

    public function list(string $examId, ?string $stage = null): array
    {
        $pattern = $stage
            ? "{$this->basePath}/{$examId}/{$stage}/*.json"
            : "{$this->basePath}/{$examId}/*/*.json";

        $files = glob($pattern);
        $snapshots = [];

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (! $data) {
                continue;
            }

            $snapshots[] = [
                'stage' => $data['stage'] ?? basename(dirname($file)),
                'label' => $data['label'] ?? basename($file, '.json'),
                'captured_at' => $data['captured_at'] ?? null,
                'hash' => isset($data['hash']) ? substr($data['hash'], 0, 8) : null,
                'file' => $file,
            ];
        }

        // Сортируем по дате (новые первые)
        usort($snapshots, function ($a, $b) {
            return ($b['captured_at'] ?? '') <=> ($a['captured_at'] ?? '');
        });

        return $snapshots;
    }

    public function listExams(): array
    {
        $dirs = glob("{$this->basePath}/*", GLOB_ONLYDIR);
        $exams = [];

        foreach ($dirs as $dir) {
            $examId = basename($dir);
            $stages = glob("{$dir}/*", GLOB_ONLYDIR);

            $exams[] = [
                'exam_id' => $examId,
                'stages' => array_map('basename', $stages),
                'snapshots_count' => count(glob("{$dir}/*/*.json")),
            ];
        }

        return $exams;
    }

    private function getSnapshotPath(string $examId, string $stage, string $label): string
    {
        $safeLabel = preg_replace('/[^a-zA-Z0-9_-]/', '_', $label);

        return "{$this->basePath}/{$examId}/{$stage}/{$safeLabel}.json";
    }

    /**
     * Получить список всех доступных этапов
     */
    public static function getAvailableStages(): array
    {
        return ['identity', 'phase_a', 'phase_b', 'resolve_plans', 'synthesis'];
    }
}
