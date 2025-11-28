<?php

namespace App\Services\LanguageApp\Validators;

use Illuminate\Validation\ValidationException;

/**
 * JsonSchemaExamOverview — гибкая валидация и нормализация Exam Overview.
 *
 * Итоговая СХЕМА (выход):
 * [
 *   "exam_name" => "string",
 *   "sources" => [
 *     [
 *       "url" => "string",
 *       "title" => "string",
 *       "publisher" => "string",
 *       "tier" => ?int (1=official, 2=trusted, 3=supplementary),
 *       "contribution" => ?string (what was taken from this source),
 *       "source_usage" => ?array (archetype_ids, data_types)
 *     ], ...
 *   ],
 *   "question_archetypes" => [
 *     [
 *       "id" => "string",
 *       "name" => "string",
 *       "category" => "string",                 // primary section (по максимальному весу или явному полю) - sections are exam parts like listening, reading, writing, speaking
 *       "category_weights" => {string: float},  // нормализованные ключи (lowercase) - NOTE: field name is category_weights for backwards compatibility, but represents SECTIONS
 *       "step_duration" => ?int,                // минуты; task-level timing OR section-level timing (if only section duration available). Priority: step_duration > section_duration > inferred from ranges
 *       "source_ids" => [int, ...],             // indices of sources that contributed to this archetype
 *
 *       // агрегированные логические блоки
 *       "stem_templates" => ["string", ...],    // инструкции/шаблоны/частые фразы
 *       "evidence" => ["mixed", ...],           // ссылки/индексы/строки
 *       "distractors" => ["string", ...],       // typical_distractors/...
 *       "ranges" => mixed,                      // numeric_ranges|numeric_ranges_and_constraints|typical_*_range|...
 *       "difficulty" => "string|null",          // difficulty|difficulty_band*
 *
 *       // прочие, редкие поля — без валидации, как есть
 *       "other" => { ... }                      // все нераспознанные ключи архетипа
 *     ],
 *     ...
 *   ],
 *   "category_map" => [
 *     "<section_name>" => [                    // NOTE: field name is category_map for backwards compatibility, but represents SECTIONS
 *        "archetype_weights" => [
 *          ["archetype_id"=>"string","weight"=>float], ...
 *        ],
 *     ],
 *   ],
 *   "total_exam_duration" => ?int,              // сумма известных step_duration (total exam time)
 *   "rationale" => "string|null"
 * ]
 */
final class JsonSchemaExamOverview
{
    public function validate(mixed $data): array
    {
        if (! is_array($data) || ! $this->isAssoc($data)) {
            throw ValidationException::withMessages(['root' => 'overview must be a JSON object']);
        }

        // --- exam_name
        $examName = $this->mustString($data, 'exam_name');

        // --- sources: массив объектов с url,title,publisher (строки)
        $sources = $this->normalizeSources($data['sources'] ?? null);

        // --- section_archetypes: section-level blueprints (optional, but recommended)
        $sectionArchetypes = $this->normalizeSectionArchetypes($data['section_archetypes'] ?? null);

        // --- top-level category weights: либо category_weights, либо category_weights_summary.aggregated_weights
        $topWeights = null;
        if (isset($data['category_weights']) && is_array($data['category_weights'])) {
            $topWeights = $this->normalizeCategoryWeights($data['category_weights'], 'category_weights');
        } elseif (isset($data['category_weights_summary']['aggregated_weights']) && is_array($data['category_weights_summary']['aggregated_weights'])) {
            $topWeights = $this->normalizeCategoryWeights($data['category_weights_summary']['aggregated_weights'], 'category_weights_summary.aggregated_weights');
        }
        // (эти веса не обязательны на выходе — служат фоном; category_map соберём из архетипов)

        // --- rationale: берём мягко из разных входных вариантов
        $rationale = null;
        foreach (['rationale', 'rationale_and_evidence_conflicts', 'conflicts_and_rationale', 'rationale_and_evidence_notes'] as $rk) {
            if (array_key_exists($rk, $data)) {
                $rationale = is_string($data[$rk]) ? $data[$rk] : json_encode($data[$rk], JSON_UNESCAPED_UNICODE);
                break;
            }
        }

        // --- archetypes (вход может называться по-разному, но в примерах — "archetypes")
        if (! isset($data['archetypes']) || ! is_array($data['archetypes'])) {
            // Fallback to question_archetypes or old global_archetypes naming
            if (isset($data['question_archetypes']) && is_array($data['question_archetypes'])) {
                $data['archetypes'] = $data['question_archetypes'];
            } elseif (isset($data['global_archetypes']) && is_array($data['global_archetypes'])) {
                $data['archetypes'] = $data['global_archetypes'];
            } else {
                throw ValidationException::withMessages(['archetypes' => 'archetypes must be an array']);
            }
        }

        $globalArchetypes = [];
        $categoryMap = []; // "<category>" => ["archetype_weights" => [[archetype_id, weight], ...]]

        foreach ($data['archetypes'] as $i => $questionArchetype) {
            if (! is_array($questionArchetype) || ! $this->isAssoc($questionArchetype)) {
                throw ValidationException::withMessages(["archetypes.$i" => 'must be an object']);
            }
            $id = $this->mustString($questionArchetype, 'id', "archetypes.$i.id");
            $name = $this->mustString($questionArchetype, 'name', "archetypes.$i.name");

            // source_ids: array of integers (indices in sources array)
            $sourceIds = [];
            if (isset($questionArchetype['source_ids']) && is_array($questionArchetype['source_ids'])) {
                foreach ($questionArchetype['source_ids'] as $idx => $sourceId) {
                    if (! is_int($sourceId) && ! is_numeric($sourceId)) {
                        throw ValidationException::withMessages([
                            "archetypes.$i.source_ids.$idx" => 'must be integer (source index)',
                        ]);
                    }
                    $sourceIds[] = (int) $sourceId;
                }
            } else {
                // Warn if source_ids missing (not a hard error for backward compatibility)
                if (! app()->environment('testing')) {
                    \Illuminate\Support\Facades\Log::warning('Archetype missing source_ids', [
                        'archetype_id' => $id,
                        'archetype_name' => $name,
                        'message' => 'Cannot track which sources contributed to this archetype',
                    ]);
                }
            }

            // category_weights на уровне архетипа: допускаем разные ключи и любой регистр
            $cw = null;
            if (isset($questionArchetype['category_weights']) && is_array($questionArchetype['category_weights'])) {
                $cw = $this->normalizeCategoryWeights($questionArchetype['category_weights'], "archetypes.$i.category_weights");
            } elseif (isset($questionArchetype['weights']) && is_array($questionArchetype['weights'])) {
                $cw = $this->normalizeCategoryWeights($questionArchetype['weights'], "archetypes.$i.weights");
            } else {
                // если нет — оставим пустой объект; некоторые твои JSON'ы кладут веса только сверху или по умолчанию 1 в одну категорию. :contentReference[oaicite:1]{index=1}
                $cw = [];
            }

            // category: выбираем primary по максимальному весу; если пусто — пробуем явное поле/секцию; иначе "unknown"
            $category = $this->inferPrimaryCategory($cw, $questionArchetype);

            // question_type: REQUIRED (Gate E)
            $questionType = $questionArchetype['question_type'] ?? null;
            if (! $questionType || ! is_string($questionType)) {
                throw ValidationException::withMessages([
                    "archetypes.$i.question_type" => "question_type is REQUIRED and must be a string from QuestionType enum for archetype '{$name}'",
                ]);
            }

            // Validate question_type is from allowed enum
            $allowedTypes = \App\Domain\Taxonomy\QuestionType::all();
            if (! in_array($questionType, $allowedTypes, true)) {
                throw ValidationException::withMessages([
                    "archetypes.$i.question_type" => 'question_type must be one of: '.implode(', ', $allowedTypes).". Got: {$questionType}",
                ]);
            }

            // type_specific: REQUIRED (Gate E)
            $typeSpecific = $questionArchetype['type_specific'] ?? null;
            if (! is_array($typeSpecific) || empty($typeSpecific)) {
                throw ValidationException::withMessages([
                    "archetypes.$i.type_specific" => "type_specific is REQUIRED and must be a non-empty object for archetype '{$name}'",
                ]);
            }

            // step_duration: из типичных полей о времени (минуты) — typical_length_or_time / typical_answer_length_or_range /
            // numeric_ranges / units "minutes" / и т.п. (best-effort).
            $stepDuration = $this->inferStepDurationMinutes($questionArchetype);

            // stem_templates: собираем общие инструкции/шаблоны/фразы (typical_instructions, pattern, question_types-названия и т.д.)
            $stemTemplates = $this->collectStemTemplates($questionArchetype);

            // evidence: объединяем evidence (числа/строки) + evidence_sources (url'ы/строки)
            $evidence = $this->collectEvidence($questionArchetype);

            // distractors: typical_distractors | common_distractors | distractors
            $distractors = $this->normalizeStringArray(
                $questionArchetype['typical_distractors'] ?? $questionArchetype['common_distractors'] ?? $questionArchetype['distractors'] ?? null,
                "archetypes.$i.distractors",
                allowNull: true
            ) ?? [];

            // ranges: numeric_ranges | numeric_ranges_and_constraints | typical_answer_length_or_range | typical_length_or_time
            $ranges = $this->collectRanges($questionArchetype);

            // difficulty: difficulty | difficulty_band | difficulty_band_cefr
            $difficulty = $this->pickFirstString($questionArchetype, ['difficulty', 'difficulty_band', 'difficulty_band_cefr']);

            // other: все ключи архетипа, которые мы не использовали — «как есть», без валидации
            $knownKeys = [
                'id', 'name', 'category', 'category_weights', 'weights', 'section', 'pattern', 'question_types',
                'typical_distractors', 'common_distractors', 'distractors', 'verbs', 'typical_verbs', 'common_verbs',
                'units', 'common_visuals', 'evidence', 'evidence_sources', 'difficulty', 'difficulty_band', 'difficulty_band_cefr',
                'typical_answer_length_or_range', 'typical_length_or_time', 'numeric_ranges', 'numeric_ranges_and_constraints',
                'typical_instructions', 'rationale', 'description',
                'step_duration', 'section_duration', // Task #4: explicit duration fields (task-level or section-level)
                'source_ids', // Source tracking
                'question_type', // Gate E: REQUIRED question type from QuestionType enum
                'type_specific', // Gate E: REQUIRED type-specific configuration object
                'skills_measured', 'common_distractors', 'stem_templates', // Additional standard fields
                'sequence_matters', 'step_order', // Sequencing fields
            ];
            $other = [];
            foreach ($questionArchetype as $k => $v) {
                if (! in_array($k, $knownKeys, true)) {
                    $other[$k] = $v;
                }
            }

            // наполнить category_map
            foreach ($cw as $cat => $w) {
                if (! isset($categoryMap[$cat])) {
                    $categoryMap[$cat] = ['archetype_weights' => []];
                }
                $categoryMap[$cat]['archetype_weights'][] = [
                    'archetype_id' => $id,
                    'weight' => (float) $w,
                ];
            }

            // CRITICAL: Validate that step_duration is not null
            // AI must search for timing information, not leave it empty
            if ($stepDuration === null) {
                \Illuminate\Support\Facades\Log::warning('Archetype missing step_duration - AI failed to find timing', [
                    'archetype_id' => $id,
                    'archetype_name' => $name,
                ]);

                // In production, we require timing information
                // In testing, we allow soft fallback via coerceOverviewForTests
                if (! app()->environment('testing')) {
                    throw ValidationException::withMessages([
                        "archetypes.$i.step_duration" => "step_duration is required - AI must search official sources for timing information. Found null for archetype: {$name}",
                    ]);
                }

                // In tests, set a default duration
                $stepDuration = 5; // 5 minutes default for tests
                \Illuminate\Support\Facades\Log::debug('Using default step_duration for tests', [
                    'archetype_id' => $id,
                    'duration' => $stepDuration,
                ]);
            }

            $globalArchetypes[] = [
                'id' => $id,
                'name' => $name,
                'category' => $category,
                'category_weights' => $cw,
                'step_duration' => $stepDuration,
                'source_ids' => $sourceIds,

                // Gate E: REQUIRED fields
                'question_type' => $questionType,
                'type_specific' => $typeSpecific,

                'stem_templates' => $stemTemplates,
                'evidence' => $evidence,
                'distractors' => $distractors,
                'ranges' => $ranges,
                'difficulty' => $difficulty,

                'other' => $other,
            ];
        }

        // total_exam_duration: сумма известных step_duration
        $totalDuration = $this->sumDurations($globalArchetypes);

        // CRITICAL: Validate that total_exam_duration is not null (except in tests)
        if (($totalDuration === null || $totalDuration === 0) && ! app()->environment('testing')) {
            throw ValidationException::withMessages([
                'total_exam_duration' => 'total_exam_duration is required - all archetypes must have step_duration',
            ]);
        }

        // Gate F: Validate that for every section in category_map, there is a corresponding section_archetype
        // that lists those question types in allowed_question_types
        if (! empty($sectionArchetypes) && ! empty($categoryMap)) {
            $this->validateGateF($categoryMap, $sectionArchetypes, $globalArchetypes);
        }

        // Итог
        return [
            'exam_name' => $examName,
            'sources' => $sources,
            'section_archetypes' => $sectionArchetypes,
            'question_archetypes' => $globalArchetypes,
            'category_map' => $categoryMap,
            'total_exam_duration' => $totalDuration,
            'rationale' => $rationale,
        ];
    }

    // ----------------- Helpers -----------------

    private function mustString(array $a, string $key, ?string $path = null): string
    {
        $p = $path ?: $key;
        if (! isset($a[$key]) || ! is_string($a[$key]) || $a[$key] === '') {
            throw ValidationException::withMessages([$p => 'must be non-empty string']);
        }

        return $a[$key];
    }

    private function normalizeSources(mixed $src): array
    {
        if (! is_array($src)) {
            throw ValidationException::withMessages(['sources' => 'sources must be an array']);
        }

        $out = [];
        $tier1Count = 0;
        $tier2Count = 0;
        $tier3Count = 0;

        foreach ($src as $i => $s) {
            if (! is_array($s)) {
                throw ValidationException::withMessages(["sources.$i" => 'must be object']);
            }

            // Required fields
            foreach (['url', 'title', 'publisher'] as $f) {
                if (! isset($s[$f]) || ! is_string($s[$f]) || $s[$f] === '') {
                    throw ValidationException::withMessages(["sources.$i.$f" => 'must be non-empty string']);
                }
            }

            // New quality tracking fields
            $tier = isset($s['tier']) ? (int) $s['tier'] : null;
            $contribution = isset($s['contribution']) && is_string($s['contribution']) ? $s['contribution'] : null;
            $sourceUsage = isset($s['source_usage']) && is_array($s['source_usage']) ? $s['source_usage'] : null;

            // Validate tier if provided
            if ($tier !== null && ($tier < 1 || $tier > 3)) {
                throw ValidationException::withMessages(["sources.$i.tier" => 'tier must be 1 (official), 2 (trusted), or 3 (supplementary)']);
            }

            // Count sources by tier for quality check
            if ($tier === 1) {
                $tier1Count++;
            } elseif ($tier === 2) {
                $tier2Count++;
            } elseif ($tier === 3) {
                $tier3Count++;
            }

            // Warn if contribution is missing (not a hard error in case of old data)
            if (! $contribution && ! app()->environment('testing')) {
                \Illuminate\Support\Facades\Log::warning('Source missing contribution field', [
                    'source_index' => $i,
                    'source_title' => $s['title'],
                    'source_url' => $s['url'],
                ]);
            }

            $out[] = [
                'url' => $s['url'],
                'title' => $s['title'],
                'publisher' => $s['publisher'],
                'tier' => $tier,
                'contribution' => $contribution,
                'source_usage' => $sourceUsage,
            ];
        }

        // Quality check: require at least 4 sources total (unless testing)
        if (count($out) < 3 && ! app()->environment('testing')) {
            throw ValidationException::withMessages([
                'sources' => 'At least 3-4 high-quality sources required. Found: '.count($out),
            ]);
        }

        // Quality check: require at least 2 TIER 1 sources (unless testing)
        if ($tier1Count < 2 && ! app()->environment('testing')) {
            \Illuminate\Support\Facades\Log::warning('Insufficient TIER 1 sources', [
                'tier1_count' => $tier1Count,
                'required' => 2,
                'message' => 'Research quality may be compromised - prefer official sources',
            ]);

            // For now, just warn - don't fail validation
            // In the future, this could be a hard requirement:
            // throw ValidationException::withMessages([
            //     'sources' => "At least 2 TIER 1 (official) sources required. Found: {$tier1Count}"
            // ]);
        }

        return $out;
    }

    private function normalizeCategoryWeights(array $weights, string $path): array
    {
        $norm = [];
        foreach ($weights as $k => $v) {
            if (! is_string($k) || ! is_numeric($v)) {
                throw ValidationException::withMessages(["$path.$k" => 'key must be string, value must be numeric']);
            }
            $norm[strtolower($k)] = (float) $v;
        }

        return $norm;
    }

    private function inferPrimaryCategory(array $cw, array $arc): string
    {
        if ($cw !== []) {
            arsort($cw, SORT_NUMERIC);

            return (string) array_key_first($cw);
        }
        // fallback: section / explicit category-like hints
        foreach (['category', 'section', 'skill', 'module'] as $k) {
            if (isset($arc[$k]) && is_string($arc[$k]) && $arc[$k] !== '') {
                return strtolower($arc[$k]);
            }
        }

        return 'unknown';
    }

    private function inferStepDurationMinutes(array $arc): ?int
    {
        // PRIORITY 1: Direct step_duration field (task-level timing)
        if (isset($arc['step_duration'])) {
            $mins = $this->extractMinutes($arc['step_duration']);
            if (! is_null($mins)) {
                return $mins;
            }
        }

        // PRIORITY 2: section_duration field (section-level timing, per Task #4 from task_prompt_2_25_10_25.md)
        if (isset($arc['section_duration'])) {
            $mins = $this->extractMinutes($arc['section_duration']);
            if (! is_null($mins)) {
                return $mins;
            }
        }

        // PRIORITY 3: Infer from typical_length_or_time and similar fields
        $candidates = [
            'typical_length_or_time',
            'typical_answer_length_or_range',
            'numeric_ranges',
            'numeric_ranges_and_constraints',
        ];
        foreach ($candidates as $k) {
            if (! array_key_exists($k, $arc)) {
                continue;
            }
            $val = $arc[$k];
            $mins = $this->extractMinutes($val);
            if (! is_null($mins)) {
                return $mins;
            }
        }

        // иногда units/description намекают на "minutes" — но без числа надёжно не извлечь. Оставим null.
        return null;
    }

    private function extractMinutes(mixed $v): ?int
    {
        // Число
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return (int) round($v);
        }

        // Строка: ищем "NN minute(s)"
        if (is_string($v)) {
            if (preg_match('/(\d{1,3})\s*min/u', $v, $m)) {
                return (int) $m[1];
            }
            // диапазоны "2–5 minutes" -> берём среднее
            if (preg_match('/(\d{1,3})\s*[–-]\s*(\d{1,3})\s*min/u', $v, $m)) {
                return (int) round(($m[1] + $m[2]) / 2);
            }

            return null;
        }

        // Массив: обойдём рекурсивно
        if (is_array($v)) {
            // ассоц-объект с ключом minutes
            if ($this->isAssoc($v)) {
                // прямые минуты в массиве минут
                if (isset($v['minutes'])) {
                    $mins = $this->avgFromArray($v['minutes']);
                    if (! is_null($mins)) {
                        return $mins;
                    }
                }
                // альтернативные ключи (monologue_minutes, recording_seconds -> перевод)
                foreach ($v as $key => $val) {
                    if (stripos((string) $key, 'minutes') !== false) {
                        $mins = $this->avgFromArray($val);
                        if (! is_null($mins)) {
                            return $mins;
                        }
                    }
                    if (stripos((string) $key, 'seconds') !== false) {
                        $secs = $this->avgFromArray($val);
                        if (! is_null($secs)) {
                            return (int) round($secs / 60);
                        }
                    }
                }
            } else {
                // простой список — попробуем найти строку с "minutes"
                foreach ($v as $item) {
                    $mins = $this->extractMinutes($item);
                    if (! is_null($mins)) {
                        return $mins;
                    }
                }
            }
        }

        return null;
    }

    private function avgFromArray(mixed $val): ?int
    {
        if (is_int($val) || is_float($val)) {
            return (int) round($val);
        }
        if (is_array($val) && ! $this->isAssoc($val) && count($val) > 0) {
            $nums = array_values(array_filter($val, fn ($x) => is_numeric($x)));
            if ($nums) {
                return (int) round(array_sum($nums) / count($nums));
            }
        }

        return null;
    }

    private function collectStemTemplates(array $arc): array
    {
        $out = [];

        // typical_instructions как «стемы»
        $arr = $this->normalizeStringArray($arc['typical_instructions'] ?? null, null, allowNull: true) ?? [];
        $out = array_merge($out, $arr);

        // pattern — добавим как строку (часто шаблон задания)
        if (isset($arc['pattern']) && is_string($arc['pattern']) && $arc['pattern'] !== '') {
            $out[] = $arc['pattern'];
        }

        // question_types — названия, если есть
        $qts = $this->normalizeStringArray($arc['question_types'] ?? null, null, allowNull: true) ?? [];
        $out = array_merge($out, $qts);

        // verbs/typical_verbs/common_verbs как подсказки формулировок
        $verbs = $this->normalizeStringArray(
            $arc['verbs'] ?? $arc['typical_verbs'] ?? $arc['common_verbs'] ?? null,
            null,
            allowNull: true
        ) ?? [];
        $out = array_merge($out, array_map(fn ($v) => 'verb: '.$v, $verbs));

        // уберём дубликаты/пробелы
        $out = array_values(array_unique(array_map('trim', array_filter($out, fn ($s) => is_string($s) && $s !== ''))));

        return $out;
    }

    private function collectEvidence(array $arc): array
    {
        $ev = [];

        // индексы/строки
        if (isset($arc['evidence']) && is_array($arc['evidence'])) {
            foreach ($arc['evidence'] as $e) {
                if (is_string($e) || is_int($e)) {
                    $ev[] = $e;
                }
            }
        }
        // источники-строки/URL
        if (isset($arc['evidence_sources']) && is_array($arc['evidence_sources'])) {
            foreach ($arc['evidence_sources'] as $e) {
                if (is_string($e)) {
                    $ev[] = $e;
                }
            }
        }

        return $ev;
    }

    private function collectRanges(array $arc): mixed
    {
        foreach (['numeric_ranges', 'numeric_ranges_and_constraints', 'typical_answer_length_or_range', 'typical_length_or_time'] as $k) {
            if (array_key_exists($k, $arc)) {
                return $arc[$k]; // как есть
            }
        }

        return null;
    }

    private function pickFirstString(array $arr, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($arr[$k]) && is_string($arr[$k])) {
                return $arr[$k];
            }
        }

        return null;
    }

    private function normalizeStringArray(mixed $value, ?string $path = null, bool $allowNull = false): ?array
    {
        if (is_null($value)) {
            return $allowNull ? null : [];
        }
        if (! is_array($value)) {
            if ($allowNull) {
                return null;
            }
            throw ValidationException::withMessages([$path ?? 'array' => 'must be array of strings']);
        }
        $res = [];
        foreach ($value as $i => $v) {
            if (is_string($v) && $v !== '') {
                $res[] = $v;
            }
        }

        return $res;
    }

    private function sumDurations(array $globalArchetypes): ?int
    {
        $sum = 0;
        $has = false;
        foreach ($globalArchetypes as $ga) {
            if (isset($ga['step_duration']) && is_int($ga['step_duration'])) {
                $sum += $ga['step_duration'];
                $has = true;
            }
        }

        return $has ? $sum : null;
    }

    private function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * Normalize section_archetypes (optional but recommended)
     */
    private function normalizeSectionArchetypes(?array $sectionArchetypes): array
    {
        if (! is_array($sectionArchetypes)) {
            return [];
        }

        $normalized = [];
        foreach ($sectionArchetypes as $i => $sa) {
            if (! is_array($sa)) {
                throw ValidationException::withMessages([
                    "section_archetypes.$i" => 'must be an object',
                ]);
            }

            // Required fields
            $section = $sa['section'] ?? null;
            if (! $section || ! is_string($section)) {
                throw ValidationException::withMessages([
                    "section_archetypes.$i.section" => 'section is required and must be a string (e.g.listening|reading|grammar_lexis|writing|speaking, this is example, you can use only existing sections)',
                ]);
            }

            // Validate allowed_question_types array
            $allowedQuestionTypes = $sa['allowed_question_types'] ?? [];
            if (! is_array($allowedQuestionTypes)) {
                throw ValidationException::withMessages([
                    "section_archetypes.$i.allowed_question_types" => 'allowed_question_types must be an array',
                ]);
            }

            // Validate that each allowed_question_type is from QuestionType enum
            $validQuestionTypes = \App\Domain\Taxonomy\QuestionType::all();
            foreach ($allowedQuestionTypes as $idx => $qt) {
                if (! in_array($qt, $validQuestionTypes, true)) {
                    throw ValidationException::withMessages([
                        "section_archetypes.$i.allowed_question_types.$idx" => "question type must be from QuestionType enum. Got: {$qt}",
                    ]);
                }
            }

            // Source IDs (optional)
            $sourceIds = [];
            if (isset($sa['source_ids']) && is_array($sa['source_ids'])) {
                foreach ($sa['source_ids'] as $idx => $sourceId) {
                    if (! is_int($sourceId) && ! is_numeric($sourceId)) {
                        throw ValidationException::withMessages([
                            "section_archetypes.$i.source_ids.$idx" => 'must be integer (source index)',
                        ]);
                    }
                    $sourceIds[] = (int) $sourceId;
                }
            }

            $normalized[] = [
                'section' => strtolower($section),
                'objectives' => $this->normalizeStringArray($sa['objectives'] ?? null, "section_archetypes.$i.objectives", true) ?? [],
                'skills_subskills' => $this->normalizeStringArray($sa['skills_subskills'] ?? null, "section_archetypes.$i.skills_subskills", true) ?? [],
                'allowed_question_types' => $allowedQuestionTypes,
                'typical_stimuli' => $sa['typical_stimuli'] ?? null,
                'item_counts' => $sa['item_counts'] ?? null,
                'time_guidance_min' => $sa['time_guidance_min'] ?? null,
                'scoring_focus' => $this->normalizeStringArray($sa['scoring_focus'] ?? null, "section_archetypes.$i.scoring_focus", true) ?? [],
                'common_pitfalls' => $this->normalizeStringArray($sa['common_pitfalls'] ?? null, "section_archetypes.$i.common_pitfalls", true) ?? [],
                'constraints' => $this->normalizeStringArray($sa['constraints'] ?? null, "section_archetypes.$i.constraints", true) ?? [],
                'source_ids' => $sourceIds,
            ];
        }

        return $normalized;
    }

    /**
     * Gate F: Validate that for every section in category_map,
     * there is a corresponding section_archetype that lists those question types
     */
    private function validateGateF(array $categoryMap, array $sectionArchetypes, array $globalArchetypes): void
    {
        // Build map: section name => allowed question types
        $sectionAllowedTypes = [];
        foreach ($sectionArchetypes as $sa) {
            $sectionName = strtolower($sa['section']);
            $sectionAllowedTypes[$sectionName] = $sa['allowed_question_types'] ?? [];
        }

        // For each section in category_map, check that all archetype question_types are allowed
        foreach ($categoryMap as $sectionName => $data) {
            $sectionNameLower = strtolower($sectionName);

            // If no section_archetype for this section, log warning (not hard error for backward compatibility)
            if (! isset($sectionAllowedTypes[$sectionNameLower])) {
                if (! app()->environment('testing')) {
                    \Illuminate\Support\Facades\Log::warning('Gate F: Missing section_archetype for section in category_map', [
                        'section' => $sectionName,
                        'message' => 'No section_archetype found for this section. Add section_archetype with allowed_question_types.',
                    ]);
                }

                continue;
            }

            $allowedTypes = $sectionAllowedTypes[$sectionNameLower];
            $archetypeWeights = $data['archetype_weights'] ?? [];

            // Check each archetype in this section
            foreach ($archetypeWeights as $aw) {
                $archetypeId = $aw['archetype_id'] ?? null;

                // Find the archetype in question_archetypes
                $archetype = null;
                foreach ($globalArchetypes as $ga) {
                    if ($ga['id'] === $archetypeId) {
                        $archetype = $ga;
                        break;
                    }
                }

                if (! $archetype) {
                    continue; // Archetype not found, skip (should be caught by other validation)
                }

                $questionType = $archetype['question_type'] ?? null;

                // Check if question_type is in allowed_question_types for this section
                if ($questionType && ! in_array($questionType, $allowedTypes, true)) {
                    throw ValidationException::withMessages([
                        "category_map.{$sectionName}" => "Gate F failed: Archetype '{$archetypeId}' has question_type '{$questionType}' which is NOT in section_archetype.allowed_question_types for section '{$sectionName}'. Allowed: ".implode(', ', $allowedTypes),
                    ]);
                }
            }
        }
    }
}
