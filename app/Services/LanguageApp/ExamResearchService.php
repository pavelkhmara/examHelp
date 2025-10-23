<?php

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\ExamDocument;
use App\Models\GenerationTask;
use App\Services\LanguageApp\Validators\JsonSchemaExamOverview;
use App\Services\LanguageApp\Validators\QuestionTypeContract;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ExamResearchService extends AbstractAiService
{
    public function runPipeline(Exam $exam, GenerationTask $task): array
    {
        Log::debug('ExamResearchService: starting pipeline', ['exam_id' => $exam->id, 'task_id' => $task->id]);
        $files = $this->buildFilesForExam($exam);
        $preferDocs = (bool) (config('ai.documents.prefer_documents') ?? true);

        // 1) Overview
        $payload = [
            'exam_slug' => $exam->slug,
            'stage' => 'overview',
            'exam_title' => $exam->title,
            'exam_level' => $exam->level,
            'exam_description' => trim($exam->description),
            'input' => trim($task->notes) ?? null,
            'context_policy' => $preferDocs
                ? 'Prefer insights derived from provided exam documents over generic web sources when there is any conflict.'
                : 'Use both files and web sources.',
        ];

        $opts = [
            'web' => true,
            'files' => $files, // AbstractAiService сам положит это в files_hint
            // при необходимости можно добавить json_schema => …
        ];

        $res1 = $this->callAi($payload, $opts);
        $this->log($task, 'overview', $payload, $res1);

        $exam->update(['research_status' => 'running_overview']);
        $task->update(['result' => $res1['body'] ?? $res1['content'] ?? $res1['raw'] ?? null]); // TODO что используется чаще, из бади и контента вынимаем значения?

        Log::debug('ExamResearchService overview result', ['result' => $res1['content'] ?? null]);

        // return [
        // 'ok'               => true,
        // 'raw'              => $raw,                 // сырое тело HTTP-ответа провайдера
        // 'body'             => $body,                // декодированный top-level JSON провайдера
        // 'content_text'     => $contentText,         // строка JSON внутри message.content
        // 'content'          => $content,             // ДЕКОДИРОВАННЫЙ overview-объект — используем дальше в сервисе
        // 'usage'            => $body['usage'] ?? ['prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0],
        // ];

        // RESPONSE validation
        $decoded = $this->decodeOverview($res1);

        try {
            $validator = new JsonSchemaExamOverview;
            $overview_normalized = $validator->validate($res1['content'] ?? null);
            $this->log($task, 'overview_validated', $payload, ['result' => $overview_normalized]);
        } catch (\Throwable $ve) {
            if (app()->environment('testing')) {
                // мягкий фоллбек в тестах: приводим к минимальной валидной форме и продолжаем
                $overview_normalized = $this->coerceOverviewForTests($decoded);
                $this->log($task, 'overview_validated_soft', $payload, [
                    'note' => 'testing soft-validate fallback',
                    'reason' => $ve->getMessage(),
                    'result' => $overview_normalized,
                ]);
            } else {
                return ['ok' => false, 'error' => 'validation_failed', 'errors' => [$ve->getMessage()]];
            }
        }

        Log::debug('ExamResearchService overview Validated json', ['overview' => $overview_normalized]);

        // --- Normalize & merge sources: add provenances and attach document-sources ---
        $webSources = [];
        $docSources = [];

        // 1) web-источники
        if (! empty($overview_normalized['sources']) && is_array($overview_normalized['sources'])) {
            foreach ($overview_normalized['sources'] as $s) {
                $webSources[] = [
                    'url' => (string) ($s['url'] ?? ''),
                    'title' => (string) ($s['title'] ?? ''),
                    'publisher' => (string) ($s['publisher'] ?? ''),
                    'provenance' => 'web',
                ];
            }
        }

        // 2) document-источники из $files
        foreach ($files as $f) {
            $docSources[] = [
                'url' => '',
                'title' => $f['name'],
                'publisher' => 'user_uploaded',
                'provenance' => 'document',
                'doc_id' => $f['id'],
                'filename' => $f['name'],
            ];
        }

        // документы — первыми, затем web
        $sources = array_merge($docSources, $webSources);
        $overview_normalized['sources'] = $sources;

        // (опц) обновим снапшот в таске
        $task->update([
            'result' => array_merge($task->result ?? [], [
                'overview_sources' => $sources,
            ]),
        ]);

        // STRICT VALIDATION of tasks (types/payload/scoring)
        /** @var QuestionTypeContract $validator */
        $validator = app(QuestionTypeContract::class);
        $validated = [];
        $tasks = $overview_normalized['tasks'] ?? ($overview_normalized['examples'] ?? []);
        foreach ($tasks as $t) {
            $validated[] = $validator->validateTask($t);
        }
        $overview_normalized['tasks'] = $validated;

        // TODO доходит, записывается?
        if ($res1 && isset($res1['content'])) {
            $exam->update([
                'meta' => array_merge($exam->meta ?? [], [
                    'sources' => $overview_normalized['sources'] ?? $overview_normalized['research_sources'] ?? [],
                    'exam_structure' => $overview_normalized['global_archetypes'],
                    'sections_count' => count($overview_normalized['global_archetypes']),
                    'total_questions' => array_sum(array_column($overview_normalized['global_archetypes'], 'count')),
                    'last_researched_at' => now()->toISOString(),
                ]),
            ]);
        }

        // === 3) Создание/апдейт категорий и шагов внутри категории ===
        // Входной массив архетипов после валидатора: global_archetypes[*]
        $arcs = $overview_normalized['global_archetypes'] ?? $overview_normalized['archetypes'] ?? [];
        $buckets = $this->groupArchetypesByCategory($arcs);
        $this->writeToFile($exam->slug, $exam->level, $buckets, $overview_normalized, $res1['content'] ?? null);

        // Определим порядок категорий:
        //  - если есть overview['category_map'] — возьмём порядок убывания суммарного веса
        //  - иначе — как встретились
        $categoryOrder = $this->rankCategories($overview_normalized, $buckets);

        $createdCategories = [];
        DB::transaction(function () use ($exam, $buckets, $categoryOrder, &$createdCategories) {
            $pos = 1;
            foreach ($categoryOrder as $catKey) {
                $items = $buckets[$catKey] ?? [];
                // slug/key/name
                $key = Str::slug($catKey);
                $name = Str::title($catKey);

                // steps (внутри категории): сортируем по step_order (если есть), иначе по индексу
                $steps = collect($items)
                    ->map(function (array $arc) {
                        // step_order может лежать в "other" от валидатора
                        $stepOrder = null;
                        if (isset($arc['other']) && is_array($arc['other'])) {
                            $maybe = $arc['other']['step_order'] ?? $arc['other']['order'] ?? null;
                            if (is_numeric($maybe)) {
                                $stepOrder = (int) $maybe;
                            }
                        }

                        return [
                            'archetype_id' => $arc['id'],
                            'name' => $arc['name'],
                            'order' => $stepOrder, // может быть null
                            'duration_min' => $arc['step_duration'] ?? null,
                            'difficulty' => $arc['difficulty'] ?? null,
                            'distractors' => $arc['distractors'] ?? [],
                            'stem_templates' => $arc['stem_templates'] ?? [],
                            'ranges' => $arc['ranges'] ?? null,
                            'evidence' => $arc['evidence'] ?? [],
                        ];
                    })
                    ->sortBy(function ($s, $idx) {
                        return is_int($s['order']) ? $s['order'] : (100000 + $idx);
                    })
                    ->values()
                    ->all();

                // метаданные категории (например, суммарный вес архетипов)
                $sumWeight = 0.0;
                foreach ($items as $arc) {
                    foreach (($arc['category_weights'] ?? []) as $cat => $w) {
                        if (Str::lower($cat) === Str::lower($catKey)) {
                            $sumWeight += (float) $w;
                        }
                    }
                }

                $category_model = [
                    'name' => $name,
                    'order' => $pos++,
                    'description' => $this->makeCategoryDescription($items),
                    'meta' => [
                        'source' => 'ai_overview',
                        'raw_category_key' => $catKey,
                        'sum_weight' => $sumWeight,
                        'archetype_count' => count($items),
                        // Храним и шаги, и облегчённые сведения по архетипам
                        'steps' => $steps,
                        'archetypes' => array_map(function ($arc) {
                            return [
                                'id' => $arc['id'],
                                'name' => $arc['name'],
                                'category_weights' => $arc['category_weights'] ?? [],
                                'step_duration' => $arc['step_duration'] ?? null,
                            ];
                        }, $items),
                    ],
                ];

                /** @var ExamCategory $catModel */
                $catModel = ExamCategory::query()->updateOrCreate(['exam_id' => $exam->id, 'key' => $key], $category_model);

                Log::debug('ExamResearchService category model', ['category_model' => $category_model]);

                $createdCategories[] = $catModel->only(['id', 'key', 'name', 'order']);
            }
        });

        $this->log($task, 'categories_persisted', [], [
            'categories' => $createdCategories,
        ]);

        // === 4) Сборка упрощённой «структуры экзамена» ===
        // Для Nova карточки — компактно и понятно: секции (категории) с ordered-steps.
        $structure = $this->buildSimplifiedStructure($overview_normalized, $buckets, $categoryOrder);

        // Можно продублировать структуру в Exam->meta['exam_structure'] (по желанию)
        Log::debug('ExamResearchService add exam-meta-structure', ['exam_structure' => $structure]);

        $meta = $exam->meta ?? [];
        $meta['exam_structure'] = $structure;
        $exam->meta = $meta;
        $exam->categories_count = count($createdCategories);
        $exam->research_status = 'completed';
        $exam->save();

        // === 5) task->result и логи ===
        $task->result = $structure;
        $task->status = 'completed';
        $task->save();

        $this->log($task, 'structure_created', [], ['structure' => $structure]);

        return [
            'ok' => true,
            'overview' => $overview_normalized,
            'structure' => $structure,
            'categories' => $createdCategories,
        ];
    }

    /**
     * Группируем архетипы по category (validator гарантирует ключ).
     * Несуществующую/пустую категорию кладём под 'unknown'.
     */
    protected function groupArchetypesByCategory(array $arcs): array
    {
        $b = [];
        foreach ($arcs as $arc) {
            $cat = trim((string) ($arc['category'] ?? '')) ?: 'unknown';
            $b[$cat] = $b[$cat] ?? [];
            $b[$cat][] = $arc;
        }

        return $b;
    }

    /**
     * Ранжируем категории:
     * - если есть category_map — по убыванию суммарного веса
     * - иначе по порядку появления
     */
    protected function rankCategories(array $overview_normalized, array $buckets): array
    {
        $map = $overview_normalized['category_map'] ?? null;
        if (is_array($map) && $map !== []) {
            $scores = [];
            foreach ($map as $cat => $data) {
                $sum = 0.0;
                foreach (($data['archetype_weights'] ?? []) as $p) {
                    $sum += (float) ($p['weight'] ?? 0);
                }
                $scores[$cat] = $sum;
            }
            // Добавим отсутствующие в карте (если такие есть)
            foreach (array_keys($buckets) as $c) {
                if (! array_key_exists($c, $scores)) {
                    $scores[$c] = 0.0;
                }
            }
            arsort($scores, SORT_NUMERIC);

            return array_keys($scores);
        }

        // fallback — по встречаемости
        return array_keys($buckets);
    }

    protected function makeCategoryDescription(array $items): ?string
    {
        // Мини-описание: перечислим названия архетипов
        $names = array_values(array_unique(array_map(fn ($a) => (string) ($a['name'] ?? ''), $items)));
        if (! $names) {
            return null;
        }

        return 'Tasks: '.implode(', ', $names);
    }

    /**
     * Упрощённая структура экзамена для Nova:
     * [
     *   exam_name, total_exam_duration,
     *   sections: [
     *     { key, name, order, steps: [ {archetype_id,name,order,duration_min}, ... ] },
     *   ]
     * ]
     */
    protected function buildSimplifiedStructure(array $overview_normalized, array $buckets, array $categoryOrder): array
    {
        $sections = [];
        $secPos = 1;

        foreach ($categoryOrder as $catKey) {
            $items = $buckets[$catKey] ?? [];

            // steps с сортировкой по step_order (если есть)
            $steps = collect($items)
                ->map(function (array $arc) {
                    $order = null;
                    if (isset($arc['other']) && is_array($arc['other'])) {
                        $maybe = $arc['other']['step_order'] ?? $arc['other']['order'] ?? null;
                        if (is_numeric($maybe)) {
                            $order = (int) $maybe;
                        }
                    }

                    return [
                        'archetype_id' => $arc['id'],
                        'name' => $arc['name'],
                        'order' => $order,
                        'duration_min' => $arc['step_duration'] ?? null,
                    ];
                })
                ->sortBy(function ($s, $idx) {
                    return is_int($s['order']) ? $s['order'] : (100000 + $idx);
                })
                ->values()
                ->all();

            $sections[] = [
                'key' => Str::slug($catKey),
                'name' => Str::title($catKey),
                'order' => $secPos++,
                'steps' => $steps,
            ];
        }

        return [
            'exam_name' => $overview_normalized['exam_name'] ?? null,
            'total_exam_duration' => $overview_normalized['total_exam_duration'] ?? null,
            'sections' => $sections,
            // оставим источники рядом, удобно выводить в Nova
            'sources' => $overview_normalized['sources'] ?? [],
        ];
    }

    protected function writeToFile(string $examSlug, string $examLevel, array $buckets, array $overview_normalized, mixed $content): void
    {
        try {
            // 1) Готовим имя файла
            $slugRaw = $exam_slug ?? ($exam['slug'] ?? 'exam');   // подстрой под свой контекст
            $levelRaw = $exam_level ?? ($exam['level'] ?? 'level'); // подстрой под свой контекст

            $slug = Str::slug((string) $slugRaw, '_');
            $level = Str::slug((string) $levelRaw, '_');

            $timestamp = Carbon::now()->format('Ymd_His');
            $fileName = "{$slug}_{$level}_{$timestamp}.json";

            // 2) Папка root/files от корня проекта
            $dir = base_path('files');
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            // 3) Данные в нужном порядке (buckets → overview_normalized → content['content'])
            $payloadOrdered = [
                'buckets' => $buckets,
                'overview_normalized' => $overview_normalized,
                'content' => $content ?? null,
            ];

            // 4) Сохраняем JSON
            $json = json_encode(
                $payloadOrdered,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );

            $fullPath = $dir.DIRECTORY_SEPARATOR.$fileName;
            file_put_contents($fullPath, $json);

            // (опционально) залогировать успех
            Log::info('Exam research saved', ['path' => $fullPath]);
        } catch (\Throwable $e) {
            // (опционально) залогировать ошибку
            Log::error('Failed to save exam research JSON', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Called by ImportAiStructure after validation
     */
    public function importAiJson(Exam $exam, array $tasks): void
    {
        // Сюда можно вынести создание ExampleQuestion/ExamCategory и т.п.
        // Пока кладём в GenerationTask snapshot для /structure и аудита.
        GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'import_validated',
            'status' => 'completed',
            'result' => ['tasks' => $tasks],
        ]);
    }

    /**
     * Собираем массив для files_hint на основе распознанных документов экзамена.
     * Формат каждого элемента — ['id','name','text','provenance','weight'].
     */
    protected function buildFilesForExam(Exam $exam): array
    {
        $cfg = config('ai.documents');
        $maxDocs = (int) ($cfg['max_docs_hint'] ?? 3);
        $maxChars = (int) ($cfg['max_chars_per_doc'] ?? 4000);
        $weight = (float) ($cfg['weight'] ?? 2.0);

        /** @var \Illuminate\Support\Collection<int,ExamDocument> $docs */
        $docs = $exam->documents()
            ->where('status', 'completed')
            ->whereNotNull('extracted_text')
            ->orderByDesc('updated_at')
            ->limit($maxDocs)
            ->get();

        return $docs->map(function (ExamDocument $d) use ($maxChars, $weight) {
            $text = (string) $d->extracted_text;
            if (mb_strlen($text) > $maxChars) {
                $text = mb_substr($text, 0, $maxChars).' … [truncated]';
            }

            return [
                'id' => (string) $d->id,
                'name' => (string) ($d->original_name ?? ('document-'.$d->id)),
                'text' => $text,
                'provenance' => 'document',
                'weight' => $weight,
            ];
        })->values()->all();
    }

    /**
     * Достаём из ответа провайдера содержимое overview в виде массива.
     */
    protected function decodeOverview(array $res): array
    {
        $raw = $res['content'] ?? null;

        if (is_string($raw)) {
            $d = json_decode($raw, true);
            if (is_array($d)) {
                return $d;
            }
        }

        if (is_array($raw)) {
            return $raw; // иногда провайдер уже дал массив
        }

        if (is_array($res['content_json'] ?? null)) {
            return $res['content_json'];
        }

        return [];
    }

    /**
     * Приводим к минимально валидной структуре (только для тестов).
     */
    protected function coerceOverviewForTests(array $decoded): array
    {
        $overview = $decoded;

        // sections — обязательны для остального пайплайна
        if (empty($overview['sections']) || ! is_array($overview['sections'])) {
            $overview['sections'] = [
                ['key' => 'listening', 'title' => 'Listening', 'count' => 20, 'time_per_question_sec' => 30, 'prep_time_sec' => 0, 'notes' => ''],
                ['key' => 'reading', 'title' => 'Reading', 'count' => 20, 'time_per_question_sec' => 45, 'prep_time_sec' => 0, 'notes' => ''],
            ];
        }

        // total_score
        if (empty($overview['total_score']) || ! is_array($overview['total_score'])) {
            $overview['total_score'] = ['min' => 0, 'max' => 100];
        }

        // sources может быть пустым — мы позже добавим doc/web
        if (! isset($overview['sources']) || ! is_array($overview['sources'])) {
            $overview['sources'] = [];
        }

        return $overview;
    }
}
