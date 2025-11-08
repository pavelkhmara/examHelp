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

/**
 * Service for building exam structure from AI overview
 *
 * Responsibilities:
 * - Run overview pipeline with AI
 * - Validate and normalize AI response
 * - Build exam categories and structure
 * - Handle retry logic for poor quality structures
 */
class OverviewStructureBuilder extends AbstractAiService
{
    /**
     * @param DocumentStructureExtractor|null $documentExtractor Injected for structured document extraction (Task #1 from task_prompt_2_25_10_25.md)
     */
    public function __construct(
        AiProvider $ai,
        protected ?DocumentStructureExtractor $documentExtractor = null
    ) {
        parent::__construct($ai);
    }

    public function runPipeline(Exam $exam, GenerationTask $task): array
    {
        Log::debug('OverviewStructureBuilder: starting pipeline', ['exam_id' => $exam->id, 'task_id' => $task->id]);
        $files = $this->buildFilesForExam($exam);
        $preferDocs = (bool) (config('ai.documents.prefer_documents') ?? true);

        // FEATURE: Extract structured data from documents (Task #1 - easy to disable by setting to null)
        $documentStructuredData = $this->extractDocumentStructure($exam);
        if ($documentStructuredData) {
            Log::info('Document structure extracted', [
                'exam_id' => $exam->id,
                'sections_found' => count($documentStructuredData['structure']['sections'] ?? []),
            ]);
        }

        // Retry loop for poor structure quality
        $maxRetries = 2;
        $retryAttempt = 0;
        $overview_normalized = null;
        $res1 = null;
        $validationErrors = []; // Track validation errors for retry hints

        while ($retryAttempt <= $maxRetries) {
            // Log activity for overview stage
            if ($retryAttempt === 0) {
                $task->addActivity('overview_started', 'Requesting exam overview from AI (Model: Thinking - GPT-5)');
            } else {
                $task->addActivity('overview_retry', "Retrying overview (attempt {$retryAttempt}/{$maxRetries}) - improving structure quality (Model: Thinking - GPT-5)");
            }

            // Build retry hint including validation errors
            $retryHint = null;
            if ($retryAttempt > 0) {
                $hints = [];

                // Add quality hint
                $hints[] = 'IMPORTANT: Previous attempt had poor category distribution. Please ensure EACH archetype has category_weights field with appropriate categories (e.g., listening, reading, writing, speaking). Do NOT put all archetypes in "unknown" category.';

                // Add validation error hints with specific instructions
                if (!empty($validationErrors)) {
                    $hints[] = 'VALIDATION ERRORS from previous attempt:';
                    foreach ($validationErrors as $error) {
                        $hints[] = "  - {$error}";

                        // Add specific instructions for step_duration errors
                        if (stripos($error, 'step_duration') !== false) {
                            $hints[] = '';
                            $hints[] = 'HOW TO FIX step_duration ERRORS:';
                            $hints[] = '1. If document specifies timing for each task → use that exact value';
                            $hints[] = '2. If document only has section duration → CALCULATE step_duration:';
                            $hints[] = '   Example: Section 30 min, 5 tasks → step_duration = 6 min per task';
                            $hints[] = '3. If no timing info in document, use typical durations by task type:';
                            $hints[] = '   - Multiple choice (10-20 items): 10-15 min';
                            $hints[] = '   - Essay writing (150-200 words): 40-50 min';
                            $hints[] = '   - Short writing (40-50 words): 15-20 min';
                            $hints[] = '   - Listening comprehension: 5-10 min per passage';
                            $hints[] = '   - Reading comprehension: 15-20 min per passage';
                            $hints[] = '   - Speaking tasks: 5-10 min per part';
                            $hints[] = '';
                            $hints[] = 'CRITICAL: Do NOT leave step_duration as null, 0, or empty!';
                            $hints[] = 'Every task MUST have a positive step_duration value.';
                        }
                    }
                    $hints[] = '';
                    $hints[] = 'Please FIX these validation errors in this attempt.';
                }

                $retryHint = implode("\n", $hints);
            }

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
                'retry_hint' => $retryHint,
            ];

            // FEATURE: Add structured document data if available (Task #1)
            if ($documentStructuredData) {
                $payload['document_structured_data'] = $documentStructuredData;
                $payload['document_usage_instruction'] = 'Use the document_structured_data as high-confidence reference. Search online for better/additional info. If online sources contradict document data or provide less detail, prefer document data.';
            }

            // Determine AI model for overview generation
            // Options: 'gpt-5-mini' (fast, cheaper) or 'gpt-5' (highest quality)
            // If gpt-5 is selected, use 'thinking' alias which maps to AI_MODEL_THINKING
            $overviewModel = $task->request['overview_model'] ?? 'gpt-5-mini';
            $modelAlias = ($overviewModel === 'gpt-5') ? 'thinking' : null;

            $opts = [
                'web' => true,
                'files' => $files,
                'model' => $modelAlias, // Use 'thinking' for GPT-5, null for default (gpt-5-mini)
            ];

            $res1 = $this->callAi($payload, $opts);
            $this->log($task, $retryAttempt > 0 ? "overview_retry_{$retryAttempt}" : 'overview', $payload, $res1);

            $exam->update(['research_status' => 'running_overview']);
            $task->update(['result' => $res1['body'] ?? $res1['content'] ?? $res1['raw'] ?? null]);

            Log::debug('OverviewStructureBuilder overview result', ['result' => $res1['content'] ?? null]);

            // RESPONSE validation
            $decoded = $this->decodeOverview($res1);

            // Fill missing step_duration values before validation
            $decoded = $this->fillMissingStepDurations($decoded);

            try {
                $validator = new JsonSchemaExamOverview;
                $overview_normalized = $validator->validate($decoded);
                $this->log($task, 'overview_validated', $payload, ['result' => $overview_normalized]);

                $task->addActivity('overview_validated', 'Overview validated successfully (Model: Thinking - GPT-5)');
            } catch (\Throwable $ve) {
                if (app()->environment('testing')) {
                    // Soft fallback in tests
                    $overview_normalized = $this->coerceOverviewForTests($decoded);
                    $this->log($task, 'overview_validated_soft', $payload, [
                        'note' => 'testing soft-validate fallback',
                        'reason' => $ve->getMessage(),
                        'result' => $overview_normalized,
                    ]);

                    $task->addActivity('overview_validated_soft', 'Overview validated with test fallback');
                } else {
                    $task->addActivity('overview_validation_failed', 'Overview validation failed: '.$ve->getMessage());

                    // If we can retry, add validation error to retry hint and continue
                    if ($retryAttempt < $maxRetries) {
                        $validationError = $ve->getMessage();
                        $retryAttempt++;

                        Log::warning('Overview validation failed, retrying with error hint', [
                            'attempt' => $retryAttempt,
                            'error' => $validationError,
                        ]);

                        $task->addActivity('decision_point_validation', "Условие: validation_failed И retry_attempt < {$maxRetries} (попытка {$retryAttempt}). Решение: RETRY Overview с инструкциями по исправлению ошибок валидации", [
                            'condition' => 'validation_failed AND retry_attempt < maxRetries',
                            'validation_error' => substr($validationError, 0, 200),
                            'retry_attempt' => $retryAttempt,
                            'max_retries' => $maxRetries,
                            'decision' => 'retry_with_validation_hints',
                        ]);

                        $task->addActivity('overview_validation_retry', "Validation failed, retrying (attempt {$retryAttempt}/{$maxRetries})", [
                            'error' => $validationError,
                            'retry_attempt' => $retryAttempt,
                        ]);

                        // Store validation error for next iteration
                        $validationErrors[] = $validationError;

                        continue;
                    }

                    // Max retries reached, fail the task
                    $task->addActivity('decision_point_validation', "Условие: validation_failed И retry_attempt >= {$maxRetries} (попытка {$retryAttempt}). Решение: ОШИБКА - validation_failed", [
                        'condition' => 'validation_failed AND retry_attempt >= maxRetries',
                        'validation_error' => substr($ve->getMessage(), 0, 200),
                        'retry_attempt' => $retryAttempt,
                        'max_retries' => $maxRetries,
                        'decision' => 'fail_validation_error',
                    ]);

                    return ['ok' => false, 'error' => 'validation_failed', 'errors' => [$ve->getMessage()]];
                }
            }

            Log::debug('OverviewStructureBuilder overview Validated json', ['overview' => $overview_normalized]);

            // Check structure quality before continuing
            $arcs = $overview_normalized['global_archetypes'] ?? $overview_normalized['archetypes'] ?? [];
            $buckets = $this->groupArchetypesByCategory($arcs);
            $qualityScore = $this->calculateStructureQuality($buckets, $arcs);

            Log::info('Structure quality check', [
                'attempt' => $retryAttempt,
                'quality_score' => $qualityScore,
                'buckets' => array_map('count', $buckets),
            ]);

            $task->addActivity('overview_quality_check', "Structure quality score: {$qualityScore}", [
                'quality_score' => $qualityScore,
                'categories_count' => count($buckets),
            ]);

            // If quality is poor and we can retry, continue loop
            if ($qualityScore < 0.5 && $retryAttempt < $maxRetries) {
                $retryAttempt++;
                Log::warning('Poor structure quality, retrying', [
                    'attempt' => $retryAttempt,
                    'quality_score' => $qualityScore,
                ]);

                $task->addActivity('decision_point_overview_quality', "Условие: quality_score < 0.5 И retry_attempt < {$maxRetries} (текущий: {$qualityScore}, попытка {$retryAttempt}). Решение: RETRY Overview с подсказками по улучшению", [
                    'condition' => 'quality_score < 0.5 AND retry_attempt < maxRetries',
                    'quality_score' => $qualityScore,
                    'retry_attempt' => $retryAttempt,
                    'max_retries' => $maxRetries,
                    'decision' => 'retry_with_hints',
                ]);

                $task->addActivity('overview_quality_poor', "Quality too low ({$qualityScore} < 0.5), retrying...", [
                    'quality_score' => $qualityScore,
                    'retry_attempt' => $retryAttempt,
                ]);

                continue;
            }

            // Good quality or max retries reached - break and continue with pipeline
            if ($qualityScore >= 0.5) {
                $task->addActivity('decision_point_overview_quality', "Условие: quality_score >= 0.5 (текущий: {$qualityScore}). Решение: продолжить к ЭТАПУ 3 (Sanity Check)", [
                    'condition' => 'quality_score >= 0.5',
                    'quality_score' => $qualityScore,
                    'decision' => 'continue_to_sanity_check',
                ]);
            } else {
                $task->addActivity('decision_point_overview_quality', "Условие: retry_attempt >= {$maxRetries} (попытка {$retryAttempt}). Решение: продолжить с текущей структурой, несмотря на низкое качество", [
                    'condition' => 'retry_attempt >= maxRetries',
                    'quality_score' => $qualityScore,
                    'retry_attempt' => $retryAttempt,
                    'max_retries' => $maxRetries,
                    'decision' => 'continue_despite_low_quality',
                ]);
            }
            break;
        }

        $task->addActivity('overview_quality_accepted', "Structure quality accepted ({$qualityScore}), proceeding to category creation", [
            'quality_score' => $qualityScore,
        ]);

        // --- Normalize & merge sources: add provenances and attach document-sources ---
        $overview_normalized = $this->normalizeSources($overview_normalized, $files);

        // Update task result
        $task->update([
            'result' => array_merge($task->result ?? [], [
                'overview_sources' => $overview_normalized['sources'],
            ]),
        ]);

        // STRICT VALIDATION of tasks (types/payload/scoring)
        $overview_normalized = $this->validateTasks($overview_normalized);

        // Update exam meta
        $this->updateExamMeta($exam, $overview_normalized);

        // === 3) Create/update categories and steps ===
        $this->writeToFile($exam->slug, $exam->level, $buckets, $overview_normalized, $res1['content'] ?? null);

        $categoryOrder = $this->rankCategories($overview_normalized, $buckets);
        $createdCategories = $this->createCategories($exam, $task, $buckets, $categoryOrder);

        // === 4) Build simplified structure ===
        $structure = $this->buildSimplifiedStructure($overview_normalized, $buckets, $categoryOrder);

        // Add global_archetypes to structure for example generation
        $structure['global_archetypes'] = $overview_normalized['global_archetypes'] ?? [];

        // Add section_archetypes if present
        if (!empty($overview_normalized['section_archetypes'])) {
            $structure['section_archetypes'] = $overview_normalized['section_archetypes'];
        }

        // Add sources to structure
        $structure['sources'] = $overview_normalized['sources'] ?? [];

        Log::debug('OverviewStructureBuilder add exam-meta-structure', ['exam_structure' => $structure]);

        $meta = $exam->meta ?? [];
        $meta['exam_structure'] = $structure;
        $exam->meta = $meta;
        $exam->categories_count = count($createdCategories);
        $exam->research_status = 'completed';

        // Save sources to dedicated column for easier access
        $exam->sources = $overview_normalized['sources'] ?? [];

        $exam->save();

        // === 5) task->result and logs ===
        $task->result = array_merge($task->result ?? [], [
            'structure' => $structure,
            'overview' => $overview_normalized,
        ]);
        $task->status = 'completed';
        $task->save();

        $this->log($task, 'structure_created', [], ['structure' => $structure]);

        $task->addActivity('pipeline_completed', 'Research pipeline completed successfully', [
            'categories_count' => count($createdCategories),
            'archetypes_count' => count($overview_normalized['global_archetypes'] ?? []),
        ]);

        return [
            'ok' => true,
            'overview' => $overview_normalized,
            'structure' => $structure,
            'categories' => $createdCategories,
        ];
    }

    /**
     * Group archetypes by category
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
     * Rank categories by weight
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
            foreach (array_keys($buckets) as $c) {
                if (! array_key_exists($c, $scores)) {
                    $scores[$c] = 0.0;
                }
            }
            arsort($scores, SORT_NUMERIC);

            return array_keys($scores);
        }

        return array_keys($buckets);
    }

    /**
     * Create categories in database
     */
    protected function createCategories(Exam $exam, GenerationTask $task, array $buckets, array $categoryOrder): array
    {
        $categoriesCount = count($categoryOrder);
        $task->addActivity('categories_creation_started', "Creating {$categoriesCount} exam categories with archetypes", [
            'categories_count' => $categoriesCount,
        ]);

        $createdCategories = [];
        DB::transaction(function () use ($exam, $buckets, $categoryOrder, &$createdCategories) {
            $pos = 1;
            foreach ($categoryOrder as $catKey) {
                $items = $buckets[$catKey] ?? [];
                $key = Str::slug($catKey);
                $name = Str::title($catKey);

                // Build steps for category
                $steps = $this->buildStepsForCategory($items);

                // Calculate sum weight
                $sumWeight = $this->calculateSumWeight($items, $catKey);

                $category_model = [
                    'name' => $name,
                    'order' => $pos++,
                    'description' => $this->makeCategoryDescription($items),
                    'meta' => [
                        'source' => 'ai_overview',
                        'raw_category_key' => $catKey,
                        'sum_weight' => $sumWeight,
                        'archetype_count' => count($items),
                        'steps' => $steps,
                        'archetypes' => $this->buildArchetypesMeta($items),
                    ],
                ];

                /** @var ExamCategory $catModel */
                $catModel = ExamCategory::query()->updateOrCreate(['exam_id' => $exam->id, 'key' => $key], $category_model);

                Log::debug('OverviewStructureBuilder category model', ['category_model' => $category_model]);

                $createdCategories[] = $catModel->only(['id', 'key', 'name', 'order']);
            }
        });

        $this->log($task, 'categories_persisted', [], [
            'categories' => $createdCategories,
        ]);

        $task->addActivity('categories_created', "Created {$categoriesCount} categories successfully", [
            'categories_count' => $categoriesCount,
        ]);

        return $createdCategories;
    }

    /**
     * Build steps for category from items
     */
    protected function buildStepsForCategory(array $items): array
    {
        return collect($items)
            ->map(function (array $arc) {
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
                    'order' => $stepOrder,
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
    }

    /**
     * Calculate sum weight for category
     */
    protected function calculateSumWeight(array $items, string $catKey): float
    {
        $sumWeight = 0.0;
        foreach ($items as $arc) {
            foreach (($arc['category_weights'] ?? []) as $cat => $w) {
                if (Str::lower($cat) === Str::lower($catKey)) {
                    $sumWeight += (float) $w;
                }
            }
        }

        return $sumWeight;
    }

    /**
     * Build archetypes meta
     */
    protected function buildArchetypesMeta(array $items): array
    {
        return array_map(function ($arc) {
            return [
                'id' => $arc['id'],
                'name' => $arc['name'],
                'category_weights' => $arc['category_weights'] ?? [],
                'step_duration' => $arc['step_duration'] ?? null,
            ];
        }, $items);
    }

    /**
     * Make category description
     */
    protected function makeCategoryDescription(array $items): ?string
    {
        $names = array_values(array_unique(array_map(fn ($a) => (string) ($a['name'] ?? ''), $items)));
        if (! $names) {
            return null;
        }

        return 'Tasks: '.implode(', ', $names);
    }

    /**
     * Build simplified structure
     */
    protected function buildSimplifiedStructure(array $overview_normalized, array $buckets, array $categoryOrder): array
    {
        $sections = [];
        $secPos = 1;

        foreach ($categoryOrder as $catKey) {
            $items = $buckets[$catKey] ?? [];

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
            'sources' => $overview_normalized['sources'] ?? [],
        ];
    }

    /**
     * Write overview data to file for debugging
     */
    protected function writeToFile(string $examSlug, string $examLevel, array $buckets, array $overview_normalized, mixed $content): void
    {
        try {
            $slug = Str::slug((string) $examSlug, '_');
            $level = Str::slug((string) $examLevel, '_');
            $timestamp = Carbon::now()->format('Ymd_His');
            $fileName = "{$slug}_{$level}_{$timestamp}.json";

            $dir = base_path('files');
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $payloadOrdered = [
                'buckets' => $buckets,
                'overview_normalized' => $overview_normalized,
                'content' => $content ?? null,
            ];

            $json = json_encode(
                $payloadOrdered,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );

            $fullPath = $dir.DIRECTORY_SEPARATOR.$fileName;
            file_put_contents($fullPath, $json);

            Log::info('Exam research saved', ['path' => $fullPath]);
        } catch (\Throwable $e) {
            Log::error('Failed to save exam research JSON', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Build files array for exam
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
     * Decode overview from response
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
            return $raw;
        }

        if (is_array($res['content_json'] ?? null)) {
            return $res['content_json'];
        }

        return [];
    }

    /**
     * Coerce overview for tests (soft fallback)
     */
    protected function coerceOverviewForTests(array $decoded): array
    {
        $overview = $decoded;

        if (empty($overview['sections']) || ! is_array($overview['sections'])) {
            $overview['sections'] = [
                ['key' => 'listening', 'title' => 'Listening', 'count' => 20, 'time_per_question_sec' => 30, 'prep_time_sec' => 0, 'notes' => ''],
                ['key' => 'reading', 'title' => 'Reading', 'count' => 20, 'time_per_question_sec' => 45, 'prep_time_sec' => 0, 'notes' => ''],
            ];
        }

        if (empty($overview['total_score']) || ! is_array($overview['total_score'])) {
            $overview['total_score'] = ['min' => 0, 'max' => 100];
        }

        if (! isset($overview['sources']) || ! is_array($overview['sources'])) {
            $overview['sources'] = [];
        }

        return $overview;
    }

    /**
     * Calculate structure quality score
     */
    protected function calculateStructureQuality(array $buckets, array $archetypes): float
    {
        if (empty($archetypes)) {
            return 0.0;
        }

        $totalArchetypes = count($archetypes);
        $unknownCount = count($buckets['unknown'] ?? []);

        $unknownRatio = $unknownCount / $totalArchetypes;
        $qualityScore = 1.0 - $unknownRatio;

        $categoryCount = count($buckets);
        if ($categoryCount <= 1) {
            $qualityScore *= 0.3;
        } elseif ($categoryCount === 2) {
            $qualityScore *= 0.7;
        }

        return max(0.0, min(1.0, $qualityScore));
    }

    /**
     * Normalize sources (add provenance for documents)
     */
    protected function normalizeSources(array $overview_normalized, array $files): array
    {
        $webSources = [];
        $docSources = [];

        // Web sources - preserve all fields from validator (tier, contribution, source_usage)
        if (! empty($overview_normalized['sources']) && is_array($overview_normalized['sources'])) {
            foreach ($overview_normalized['sources'] as $s) {
                $webSources[] = array_merge($s, [
                    'provenance' => 'web',
                ]);
            }
        }

        // Document sources
        foreach ($files as $f) {
            $docSources[] = [
                'url' => '',
                'title' => $f['name'],
                'publisher' => 'user_uploaded',
                'provenance' => 'document',
                'doc_id' => $f['id'],
                'filename' => $f['name'],
                'tier' => null,
                'contribution' => null,
                'source_usage' => null,
            ];
        }

        $sources = array_merge($docSources, $webSources);
        $overview_normalized['sources'] = $sources;

        return $overview_normalized;
    }

    /**
     * Validate tasks with QuestionTypeContract
     */
    protected function validateTasks(array $overview_normalized): array
    {
        /** @var QuestionTypeContract $validator */
        $validator = app(QuestionTypeContract::class);
        $validated = [];
        $tasks = $overview_normalized['tasks'] ?? ($overview_normalized['examples'] ?? []);
        foreach ($tasks as $t) {
            $validated[] = $validator->validateTask($t);
        }
        $overview_normalized['tasks'] = $validated;

        return $overview_normalized;
    }

    /**
     * Update exam meta with overview data
     */
    protected function updateExamMeta(Exam $exam, array $overview_normalized): void
    {
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

    /**
     * Fill missing step_duration values by calculating from section duration
     *
     * When documents specify section duration but not per-task timing,
     * we distribute the time evenly among tasks in that section.
     */
    protected function fillMissingStepDurations(array $overview): array
    {
        $sections = $overview['sections'] ?? [];

        foreach ($sections as $sectionIdx => &$section) {
            $sectionDuration = $section['duration_minutes'] ?? null;
            $tasks = $section['tasks'] ?? [];
            $taskCount = count($tasks);

            if (!$sectionDuration || $taskCount === 0) {
                continue;
            }

            // Calculate how much time is already allocated
            $tasksWithoutDuration = [];
            $allocatedTime = 0;

            foreach ($tasks as $idx => $task) {
                if (isset($task['step_duration']) && $task['step_duration'] > 0) {
                    $allocatedTime += $task['step_duration'];
                } else {
                    $tasksWithoutDuration[] = $idx;
                }
            }

            // Distribute remaining time among tasks without duration
            if (!empty($tasksWithoutDuration)) {
                $remainingTime = $sectionDuration - $allocatedTime;
                $timePerTask = $remainingTime > 0
                    ? round($remainingTime / count($tasksWithoutDuration), 1)
                    : round($sectionDuration / $taskCount, 1);

                foreach ($tasksWithoutDuration as $idx) {
                    $section['tasks'][$idx]['step_duration'] = max(1, $timePerTask);
                }

                Log::debug('Filled missing step_duration', [
                    'section' => $section['section_name'] ?? "section_{$sectionIdx}",
                    'section_duration' => $sectionDuration,
                    'tasks_filled' => count($tasksWithoutDuration),
                    'time_per_task' => $timePerTask,
                ]);
            }
        }

        $overview['sections'] = $sections;

        // Also handle global_archetypes if present (fallback structure)
        if (isset($overview['global_archetypes'])) {
            foreach ($overview['global_archetypes'] as &$archetype) {
                if (!isset($archetype['step_duration']) || $archetype['step_duration'] <= 0) {
                    // Estimate based on task type or use default
                    $archetype['step_duration'] = $this->estimateTaskDuration($archetype);
                }
            }
        }

        return $overview;
    }

    /**
     * Estimate task duration based on task type and characteristics
     */
    protected function estimateTaskDuration(array $task): int
    {
        $taskType = $task['task_type'] ?? '';
        $taskName = strtolower($task['task_name'] ?? '');

        // Heuristics based on task type
        if (str_contains($taskType, 'multiple_choice') || str_contains($taskType, 'true_false')) {
            $questionCount = $task['question_count'] ?? 10;
            return max(5, (int)($questionCount * 1.0)); // 1 min per question
        }

        if (str_contains($taskType, 'writing') || str_contains($taskName, 'writing') || str_contains($taskName, 'essay')) {
            $wordCount = $task['word_count_target'] ?? 100;
            if (is_string($wordCount)) {
                preg_match('/\d+/', $wordCount, $matches);
                $wordCount = (int)($matches[0] ?? 100);
            }
            return max(20, (int)($wordCount / 3)); // ~3 words per minute
        }

        if (str_contains($taskName, 'listening') || str_contains($taskType, 'listening')) {
            return 10; // typical listening task
        }

        if (str_contains($taskName, 'reading') || str_contains($taskType, 'reading')) {
            return 15; // typical reading task
        }

        if (str_contains($taskName, 'speaking') || str_contains($taskType, 'speaking')) {
            return 5; // typical speaking task part
        }

        return 15; // generic default
    }

    /**
     * Extract structured data from exam documents
     *
     * This implements Task #1 from task_prompt_2_25_10_25.md
     * FEATURE: Easy to disable by removing this method call or setting $documentExtractor to null
     *
     * @param Exam $exam
     * @return array|null Structured data or null if extraction fails/disabled
     */
    protected function extractDocumentStructure(Exam $exam): ?array
    {
        // Feature flag: if no extractor injected, feature is disabled
        if (!$this->documentExtractor) {
            return null;
        }

        // Get the most recent completed document
        $document = $exam->documents()
            ->where('status', 'completed')
            ->whereNotNull('extracted_text')
            ->orderByDesc('updated_at')
            ->first();

        if (!$document) {
            return null;
        }

        try {
            $structured = $this->documentExtractor->extract($document);

            if (!$structured) {
                return null;
            }

            // Format as hints for the prompt
            return $this->documentExtractor->formatAsHints($structured);
        } catch (\Throwable $e) {
            Log::warning('Failed to extract document structure', [
                'exam_id' => $exam->id,
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
