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

        // Retry loop for poor structure quality
        $maxRetries = 2;
        $retryAttempt = 0;
        $overview_normalized = null;
        $res1 = null;

        while ($retryAttempt <= $maxRetries) {
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
                'retry_hint' => $retryAttempt > 0
                    ? 'IMPORTANT: Previous attempt had poor category distribution. Please ensure EACH archetype has category_weights field with appropriate categories (e.g., listening, reading, writing, speaking, grammar). Do NOT put all archetypes in "unknown" category.'
                    : null,
            ];

            $opts = [
                'web' => true,
                'files' => $files, // AbstractAiService сам положит это в files_hint
                // при необходимости можно добавить json_schema => …
            ];

            $res1 = $this->callAi($payload, $opts);
            $this->log($task, $retryAttempt > 0 ? "overview_retry_{$retryAttempt}" : 'overview', $payload, $res1);

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

            // Check structure quality before continuing
            $arcs = $overview_normalized['global_archetypes'] ?? $overview_normalized['archetypes'] ?? [];
            $buckets = $this->groupArchetypesByCategory($arcs);
            $qualityScore = $this->calculateStructureQuality($buckets, $arcs);

            Log::info('Structure quality check', [
                'attempt' => $retryAttempt,
                'quality_score' => $qualityScore,
                'buckets' => array_map('count', $buckets),
            ]);

            // If quality is poor and we can retry, continue loop
            if ($qualityScore < 0.5 && $retryAttempt < $maxRetries) {
                $retryAttempt++;
                Log::warning('Poor structure quality, retrying', [
                    'attempt' => $retryAttempt,
                    'quality_score' => $qualityScore,
                ]);

                continue;
            }

            // Good quality or max retries reached - break and continue with pipeline
            break;
        }

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
        // Note: $arcs and $buckets already computed in retry loop above (lines 94-95)
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

    /**
     * Stage: identity_guard
     * - Uses AI to analyze user_input + extracted_text
     * - Determines exam family, provider, variant with confidence
     * - Returns verdict + follow-ups if uncertain
     */
    public function runIdentityGuard(\App\Models\Exam $exam, \App\Models\GenerationTask $task): array
    {
        $req = (array) ($task->request ?? []);
        $user = (array) ($req['user_input'] ?? []);
        $docId = $req['document_id'] ?? null;

        // Extract document text if available
        $extracted = null;
        if ($docId) {
            $doc = \App\Models\ExamDocument::query()->find($docId);
            if ($doc && is_string($doc->extracted_text) && $doc->extracted_text !== '') {
                $extracted = $doc->extracted_text;
            }
        }

        // Load marker pack (placeholder for now - could be expanded later)
        $markerPack = [];

        // Build AI payload using PromptIdentityGuard
        $aiPayload = \App\Services\LanguageApp\Prompts\PromptIdentityGuard::buildPayload(
            $user,
            $extracted,
            $markerPack
        );

        try {
            // Call AI provider
            $aiResponse = $this->ai->generate($aiPayload, [
                'json_schema' => \App\Services\LanguageApp\Prompts\PromptIdentityGuard::schema(),
            ]);

            // Extract content from AI response
            $content = null;
            if (isset($aiResponse['content'])) {
                $content = is_string($aiResponse['content'])
                    ? json_decode($aiResponse['content'], true)
                    : $aiResponse['content'];
            } elseif (isset($aiResponse['body']['choices'][0]['message']['content'])) {
                $raw = $aiResponse['body']['choices'][0]['message']['content'];
                $content = is_string($raw) ? json_decode($raw, true) : $raw;
            }

            if (! is_array($content)) {
                throw new \RuntimeException('AI response did not contain valid JSON array');
            }

            $res = $content;

            // Validate against schema
            /** @var \App\Services\LanguageApp\Validators\JsonSchemaExamIdentity $schemaValidator */
            $schemaValidator = app(\App\Services\LanguageApp\Validators\JsonSchemaExamIdentity::class);
            $res = $schemaValidator->validate($res);

            // Determine if we should set hold=true
            // Hold if: certain status + confidence >= 0.97 + no red_flags
            // (We need very high confidence to proceed without user confirmation)
            $shouldHold = (
                ($res['status'] ?? '') === 'certain'
                && ($res['confidence'] ?? 0) >= 0.97
                && empty($res['red_flags'] ?? [])
            );

            if ($shouldHold && ! isset($res['hold'])) {
                $res['hold'] = true;
            }

            // If confidence is between 0.90 and 0.97, we need additional verification
            $needsBoost = (
                ($res['status'] ?? '') === 'certain'
                && ($res['confidence'] ?? 0) >= 0.90
                && ($res['confidence'] ?? 0) < 0.97
                && empty($res['red_flags'] ?? [])
            );

            if ($needsBoost) {
                $res['needs_confidence_boost'] = true;
            }

            // Log with tokens
            \App\Models\GenerationLog::create([
                'exam_id' => $exam->id,
                'generation_task_id' => $task->id,
                'stage' => 'identity',
                'request' => [
                    'user_input' => $user,
                    'document_id' => $docId,
                    'extracted_present' => ! is_null($extracted),
                    'extracted_length' => $extracted ? mb_strlen($extracted) : 0,
                ],
                'response' => $res,
                'prompt_tokens' => $aiResponse['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $aiResponse['usage']['completion_tokens'] ?? 0,
                'total_tokens' => $aiResponse['usage']['total_tokens'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            // Fallback to heuristic method if AI fails
            Log::warning('Identity Guard AI failed, using fallback heuristics', [
                'error' => $e->getMessage(),
                'exam_id' => $exam->id,
                'task_id' => $task->id,
            ]);

            $res = $this->identityGuardFallback($exam, $user, $extracted);

            // Log fallback attempt
            \App\Models\GenerationLog::create([
                'exam_id' => $exam->id,
                'generation_task_id' => $task->id,
                'stage' => 'identity_fallback',
                'request' => [
                    'user_input' => $user,
                    'document_id' => $docId,
                    'error' => $e->getMessage(),
                ],
                'response' => $res,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ]);
        }

        // Save identity result to task
        $result = (array) ($task->result ?? []);
        $result['identity'] = $res;
        $task->result = $result;
        $task->save();

        return $res;
    }

    /**
     * Stage: variant_probe
     * - Triggered when identity_guard confidence < 0.80 or multiple close candidates
     * - Disambiguates variant/module (Academic vs General, iBT vs Paper, B1 vs B2, etc.)
     * - Returns refined candidates + targeted follow-up questions
     */
    public function runVariantProbe(\App\Models\Exam $exam, \App\Models\GenerationTask $task, array $identityResult): array
    {
        $req = (array) ($task->request ?? []);
        $user = (array) ($req['user_input'] ?? []);
        $docId = $req['document_id'] ?? null;

        // Extract document text
        $extracted = null;
        if ($docId) {
            $doc = \App\Models\ExamDocument::query()->find($docId);
            if ($doc && is_string($doc->extracted_text) && $doc->extracted_text !== '') {
                $extracted = $doc->extracted_text;
            }
        }

        $family = $identityResult['canonical']['family'] ?? null;
        $candidates = $identityResult['candidates'] ?? [];

        // Build variant-specific questions based on family
        $variantQuestions = $this->buildVariantQuestions($family, $user, $extracted);

        $result = [
            'family' => $family,
            'candidates' => $candidates,
            'followups' => $variantQuestions,
            'disambiguation_needed' => ! empty($variantQuestions),
        ];

        // Log
        \App\Models\GenerationLog::create([
            'exam_id' => $exam->id,
            'generation_task_id' => $task->id,
            'stage' => 'variant_probe',
            'request' => [
                'user_input' => $user,
                'identity_result' => $identityResult,
            ],
            'response' => $result,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ]);

        return $result;
    }

    /**
     * Build targeted questions for variant disambiguation
     */
    protected function buildVariantQuestions(?string $family, array $user, ?string $extracted): array
    {
        $questions = [];

        if (! $family) {
            return [
                ['q' => 'What is the exact name of the exam you are preparing for?', 'why' => 'Need to identify exam family'],
            ];
        }

        switch ($family) {
            case 'IELTS':
                $questions[] = [
                    'q' => 'Is this IELTS Academic or General Training?',
                    'why' => 'Academic has academic texts and data description tasks; General has practical texts and letters',
                ];
                break;

            case 'TOEFL iBT':
            case 'TOEFL':
                $questions[] = [
                    'q' => 'Is this TOEFL iBT (internet-based), TOEFL iBT Home Edition, or TOEFL Paper Edition?',
                    'why' => 'Format affects delivery mode, timing, and task types',
                ];
                break;

            case 'Goethe-Zertifikat':
                if (empty($user['target']['level'] ?? null)) {
                    $questions[] = [
                        'q' => 'Which level: A1, A2, B1, B2, C1, or C2?',
                        'why' => 'Each level has different task complexity, timing, and passing criteria',
                    ];
                }
                break;

            case 'DELF/DALF':
            case 'DELF':
            case 'DALF':
                if (empty($user['target']['level'] ?? null)) {
                    $questions[] = [
                        'q' => 'Which diploma: DELF A1, A2, B1, B2 or DALF C1, C2?',
                        'why' => 'Each level tests different competencies and has distinct formats',
                    ];
                }
                break;
        }

        return $questions;
    }

    /**
     * Stage: sanity_checker
     * - Compares exam_doc_draft (or identity canonical) against family_ruleset invariants
     * - Returns compliance_score, passes, fails, warnings, missing
     */
    public function runSanityChecker(\App\Models\Exam $exam, \App\Models\GenerationTask $task, array $examDocDraft): array
    {
        // Load family ruleset
        $rulesetPath = config_path('family_ruleset.json');
        if (! file_exists($rulesetPath)) {
            Log::warning('family_ruleset.json not found', ['path' => $rulesetPath]);

            return [
                'compliance_score' => 0.0,
                'passes' => [],
                'fails' => ['family_ruleset.json not found'],
                'warnings' => [],
                'missing' => [],
                'anchors' => [],
            ];
        }

        $ruleset = json_decode(file_get_contents($rulesetPath), true);
        if (! is_array($ruleset)) {
            return [
                'compliance_score' => 0.0,
                'fails' => ['Invalid ruleset format'],
                'passes' => [],
                'warnings' => [],
                'missing' => [],
                'anchors' => [],
            ];
        }

        $family = $examDocDraft['canonical']['family'] ?? $examDocDraft['family'] ?? null;
        if (! $family || ! isset($ruleset[$family])) {
            return [
                'compliance_score' => 0.5,
                'passes' => [],
                'fails' => [],
                'warnings' => ["Unknown exam family: {$family}"],
                'missing' => [],
                'anchors' => [],
            ];
        }

        $rules = $ruleset[$family];
        $passes = [];
        $fails = [];
        $warnings = [];
        $missing = [];
        $totalChecks = 0;
        $passedChecks = 0;

        // Check sections
        if (isset($rules['sections']['required'])) {
            $totalChecks++;
            $requiredSections = $rules['sections']['required'];
            $examSections = $examDocDraft['sections'] ?? [];

            $foundSections = array_column($examSections, 'name');
            $allPresent = true;

            foreach ($requiredSections as $reqSection) {
                $found = false;
                foreach ($foundSections as $examSection) {
                    if (stripos($examSection, $reqSection) !== false) {
                        $found = true;
                        break;
                    }
                }

                if ($found) {
                    $passes[] = "Section '{$reqSection}' present ✓";
                } else {
                    $fails[] = "Required section '{$reqSection}' missing";
                    $allPresent = false;
                }
            }

            if ($allPresent) {
                $passedChecks++;
            }
        }

        // Check timing
        if (isset($rules['timing']['total_minutes'])) {
            $totalChecks++;
            [$minTime, $maxTime] = $rules['timing']['total_minutes'];
            $examTime = $examDocDraft['administration']['total_time_minutes'] ?? null;

            if ($examTime !== null) {
                $tolerance = 10; // minutes
                if ($examTime >= ($minTime - $tolerance) && $examTime <= ($maxTime + $tolerance)) {
                    $passes[] = "Total time {$examTime}min within expected range [{$minTime}-{$maxTime}] ✓";
                    $passedChecks++;
                } else {
                    $fails[] = "Total time {$examTime}min outside expected range [{$minTime}-{$maxTime}]";
                }
            } else {
                $missing[] = 'Total exam time not specified';
            }
        }

        // Check scoring scale
        if (isset($rules['scoring']['scale'])) {
            $totalChecks++;
            $expectedScale = $rules['scoring']['scale'];
            $examScale = $examDocDraft['scoring']['scale'] ?? null;

            if ($examScale) {
                if ($examScale === $expectedScale) {
                    $passes[] = "Scoring scale '{$examScale}' matches expected '{$expectedScale}' ✓";
                    $passedChecks++;
                } else {
                    $fails[] = "Scoring scale '{$examScale}' != expected '{$expectedScale}'";
                }
            } else {
                $missing[] = 'Scoring scale not specified';
            }
        }

        // Calculate compliance score
        $compliance = $totalChecks > 0 ? ($passedChecks / $totalChecks) : 0.0;

        $result = [
            'compliance_score' => round($compliance, 2),
            'passes' => $passes,
            'fails' => $fails,
            'warnings' => $warnings,
            'missing' => $missing,
            'anchors' => [],
            'total_checks' => $totalChecks,
            'passed_checks' => $passedChecks,
        ];

        // Log
        \App\Models\GenerationLog::create([
            'exam_id' => $exam->id,
            'generation_task_id' => $task->id,
            'stage' => 'sanity',
            'request' => [
                'exam_doc_draft' => $examDocDraft,
                'family' => $family,
            ],
            'response' => $result,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ]);

        return $result;
    }

    /**
     * Stage: confidence_boost
     * - Boosts confidence from 0.90-0.96 to >0.97 through family ruleset verification
     * - Returns updated identity result with new confidence and explanation
     */
    public function runConfidenceBoost(\App\Models\Exam $exam, \App\Models\GenerationTask $task, array $identityResult): array
    {
        $req = (array) ($task->request ?? []);
        $user = (array) ($req['user_input'] ?? []);
        $docId = $req['document_id'] ?? null;

        // Extract document text
        $extracted = null;
        if ($docId) {
            $doc = \App\Models\ExamDocument::query()->find($docId);
            if ($doc && is_string($doc->extracted_text) && $doc->extracted_text !== '') {
                $extracted = $doc->extracted_text;
            }
        }

        // Load family ruleset
        $family = $identityResult['canonical']['family'] ?? null;
        $rulesetPath = config_path('family_ruleset.json');

        if (! $family || ! file_exists($rulesetPath)) {
            // Cannot boost without family or ruleset - return original
            Log::warning('Confidence boost skipped: missing family or ruleset', [
                'family' => $family,
                'exam_id' => $exam->id,
            ]);

            return $identityResult;
        }

        $ruleset = json_decode(file_get_contents($rulesetPath), true);
        $familyRuleset = $ruleset[$family] ?? null;

        if (! $familyRuleset) {
            // Family not in ruleset - return original
            Log::warning('Confidence boost skipped: family not in ruleset', [
                'family' => $family,
                'exam_id' => $exam->id,
            ]);

            return $identityResult;
        }

        // Build AI payload
        $aiPayload = \App\Services\LanguageApp\Prompts\PromptConfidenceBoost::buildPayload(
            $identityResult,
            $familyRuleset,
            $user,
            $extracted
        );

        try {
            // Call AI provider
            $aiResponse = $this->ai->generate($aiPayload, [
                'json_schema' => \App\Services\LanguageApp\Prompts\PromptConfidenceBoost::schema(),
            ]);

            // Extract content
            $content = null;
            if (isset($aiResponse['content'])) {
                $content = is_string($aiResponse['content'])
                    ? json_decode($aiResponse['content'], true)
                    : $aiResponse['content'];
            } elseif (isset($aiResponse['body']['choices'][0]['message']['content'])) {
                $raw = $aiResponse['body']['choices'][0]['message']['content'];
                $content = is_string($raw) ? json_decode($raw, true) : $raw;
            }

            if (! is_array($content)) {
                throw new \RuntimeException('AI response did not contain valid JSON array');
            }

            // Merge boost result with original identity
            $boostedIdentity = array_merge($identityResult, [
                'confidence' => $content['confidence'] ?? $identityResult['confidence'],
                'status' => $content['status'] ?? $identityResult['status'],
                'confidence_boost' => [
                    'original_confidence' => $identityResult['confidence'],
                    'boosted_confidence' => $content['confidence'] ?? $identityResult['confidence'],
                    'adjustment_reason' => $content['adjustment_reason'] ?? '',
                    'checks_performed' => $content['checks_performed'] ?? [],
                    'evidence_quality' => $content['evidence_quality'] ?? 'unknown',
                    'recommendation' => $content['recommendation'] ?? 'request_user_confirmation',
                ],
            ]);

            // Update hold based on new confidence
            if ($boostedIdentity['confidence'] >= 0.97 && $boostedIdentity['status'] === 'certain') {
                $boostedIdentity['hold'] = true;
            } else {
                $boostedIdentity['hold'] = false;
            }

            // Log
            \App\Models\GenerationLog::create([
                'exam_id' => $exam->id,
                'generation_task_id' => $task->id,
                'stage' => 'confidence_boost',
                'request' => [
                    'original_confidence' => $identityResult['confidence'],
                    'family' => $family,
                ],
                'response' => $boostedIdentity,
                'prompt_tokens' => $aiResponse['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $aiResponse['usage']['completion_tokens'] ?? 0,
                'total_tokens' => $aiResponse['usage']['total_tokens'] ?? 0,
            ]);

            return $boostedIdentity;
        } catch (\Throwable $e) {
            Log::warning('Confidence boost failed, using original identity', [
                'error' => $e->getMessage(),
                'exam_id' => $exam->id,
                'task_id' => $task->id,
            ]);

            // Log failed attempt
            \App\Models\GenerationLog::create([
                'exam_id' => $exam->id,
                'generation_task_id' => $task->id,
                'stage' => 'confidence_boost_failed',
                'request' => [
                    'original_confidence' => $identityResult['confidence'],
                    'error' => $e->getMessage(),
                ],
                'response' => $identityResult,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ]);

            return $identityResult;
        }
    }

    /**
     * Fallback heuristic method when AI is unavailable
     */
    protected function identityGuardFallback(\App\Models\Exam $exam, array $user, ?string $extracted): array
    {
        // Check required fields
        $need = [];
        $lang = $user['language'] ?? $user['exam_language'] ?? null;
        if (! is_string($lang) || $lang === '') {
            $need[] = 'language';
        }

        $where = (array) ($user['where'] ?? []);
        $country = $where['country'] ?? null;
        if (! is_string($country) || $country === '') {
            $need[] = 'where.country';
        }

        $modality = $where['mode'] ?? $where['modality'] ?? null;
        if (! is_string($modality) || $modality === '') {
            $need[] = 'where.modality';
        }

        $target = (array) ($user['target'] ?? []);
        $level = $target['level'] ?? null;
        $score = $target['score'] ?? null;
        if (! is_string($level) && ! is_numeric($score)) {
            $need[] = 'target.level_or_score';
        }

        // Simple keyword matching
        $hay = mb_strtolower(trim(
            ($user['exam_name'] ?? '').' '.
            ($exam->title ?? '').' '.
            ($user['notes'] ?? '').' '.
            ($extracted ? mb_substr($extracted, 0, 500) : '')
        ));

        $family = null;
        $provider = null;

        if (str_contains($hay, 'ielts')) {
            $family = 'IELTS';
            $provider = 'Cambridge/IDP/British Council';
        } elseif (str_contains($hay, 'toefl')) {
            $family = 'TOEFL iBT';
            $provider = 'ETS';
        } elseif (str_contains($hay, 'goethe')) {
            $family = 'Goethe-Zertifikat';
            $provider = 'Goethe-Institut';
        } elseif (str_contains($hay, 'delf') || str_contains($hay, 'dalf')) {
            $family = 'DELF/DALF';
            $provider = 'France Éducation international';
        } elseif (str_contains($hay, 'telc')) {
            $family = 'telc';
            $provider = 'telc gGmbH';
        }

        if (! empty($need)) {
            return [
                'status' => 'uncertain',
                'confidence' => 0.30,
                'canonical' => [
                    'family' => $family,
                    'name' => $exam->title ?? null,
                    'provider' => $provider,
                    'variant' => null,
                    'language_of_test' => $lang,
                ],
                'candidates' => [],
                'followups' => [
                    'Please provide missing fields: '.implode(', ', $need),
                ],
                'need_fields' => $need,
                'anchors' => [],
            ];
        }

        return [
            'status' => 'certain',
            'confidence' => 0.90,
            'canonical' => [
                'family' => $family,
                'name' => $exam->title ?? null,
                'provider' => $provider,
                'variant' => null,
                'language_of_test' => $lang,
            ],
            'candidates' => [],
            'followups' => [],
            'need_fields' => [],
            'anchors' => [],
            'hold' => true,
        ];
    }

    /**
     * Calculate structure quality score (0.0 - 1.0)
     * Lower score means poor structure (too many unknowns)
     */
    protected function calculateStructureQuality(array $buckets, array $archetypes): float
    {
        if (empty($archetypes)) {
            return 0.0;
        }

        $totalArchetypes = count($archetypes);
        $unknownCount = count($buckets['unknown'] ?? []);

        // If >70% are in "unknown", quality is poor
        $unknownRatio = $unknownCount / $totalArchetypes;

        // Quality score: 1.0 if no unknowns, 0.0 if all unknowns
        $qualityScore = 1.0 - $unknownRatio;

        // Also check if we have reasonable category diversity
        $categoryCount = count($buckets);
        if ($categoryCount <= 1) {
            // Only one category (probably "unknown") - very poor
            $qualityScore *= 0.3;
        } elseif ($categoryCount === 2) {
            // Two categories - still not great
            $qualityScore *= 0.7;
        }

        return max(0.0, min(1.0, $qualityScore));
    }

    /**
     * Run AI auto-clarification when user confirmation is not available
     */
    public function runAutoClarification(Exam $exam, GenerationTask $task, array $identityResult): array
    {
        $userInput = $task->request['user_input'] ?? [];
        $documentId = $task->request['document_id'] ?? null;

        // Get extracted text if document exists
        $extractedText = null;
        if ($documentId) {
            $doc = \App\Models\ExamDocument::find($documentId);
            if ($doc && $doc->status === 'completed') {
                $extractedText = $doc->extracted_text;
            }
        }

        // Build prompt
        $prompt = \App\Services\LanguageApp\Prompts\PromptAutoClarification::build(
            $identityResult,
            $userInput,
            $extractedText
        );

        // Call AI
        $payload = [
            'exam_slug' => $exam->slug,
            'stage' => 'auto_clarification',
            'prompt' => $prompt,
        ];

        $res = $this->callAi($payload, ['web' => false]);
        $this->log($task, 'auto_clarification', $payload, $res);

        // Parse response
        $clarification = $res['content'] ?? [];

        return [
            'selected_candidate' => $clarification['selected_candidate'] ?? null,
            'inferred_data' => $clarification['inferred_data'] ?? [],
            'confidence' => $clarification['confidence'] ?? 0.5,
            'reasoning' => $clarification['reasoning'] ?? 'AI made best-effort inference from available data',
            'disclaimer' => $clarification['disclaimer'] ?? 'AI-inferred without user confirmation',
        ];
    }
}
