<?php

namespace App\Services\LanguageApp\Validators;

use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * JsonSchemaExamOverview — гибкая валидация и нормализация Exam Overview.
 *
 * Итоговая СХЕМА (выход):
 * [
 *   "exam_name" => "string",
 *   "sources" => [
 *     ["url"=>"string","title"=>"string","publisher"=>"string"], ...
 *   ],
 *   "global_archetypes" => [
 *     [
 *       "id" => "string",
 *       "name" => "string",
 *       "category" => "string",                 // primary category (по максимальному весу или явному полю)
 *       "category_weights" => {string: float},  // нормализованные ключи (lowercase)
 *       "step_duration" => ?int,                // минуты; из typical_length_or_time / ranges / null
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
 *     "<category_name>" => [
 *        "archetype_weights" => [
 *          ["archetype_id"=>"string","weight"=>float], ...
 *        ],
 *     ],
 *   ],
 *   "total_exam_duration" => ?int,              // сумма известных step_duration
 *   "rationale" => "string|null"
 * ]
 */
final class JsonSchemaExamOverview
{
    public function validate(mixed $data): array
    {
        if (!is_array($data) || !$this->isAssoc($data)) {
            throw ValidationException::withMessages(['root' => 'overview must be a JSON object']);
        }

        // --- exam_name
        $examName = $this->mustString($data, 'exam_name');

        // --- sources: массив объектов с url,title,publisher (строки)
        $sources = $this->normalizeSources($data['sources'] ?? null);

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
        if (!isset($data['archetypes']) || !is_array($data['archetypes'])) {
            // Иногда встречается "global_archetypes" — подстрахуемся
            if (isset($data['global_archetypes']) && is_array($data['global_archetypes'])) {
                $data['archetypes'] = $data['global_archetypes'];
            } else {
                throw ValidationException::withMessages(['archetypes' => 'archetypes must be an array']);
            }
        }

        $globalArchetypes = [];
        $categoryMap = []; // "<category>" => ["archetype_weights" => [[archetype_id, weight], ...]]

        foreach ($data['archetypes'] as $i => $arc) {
            if (!is_array($arc) || !$this->isAssoc($arc)) {
                throw ValidationException::withMessages(["archetypes.$i" => 'must be an object']);
            }
            $id   = $this->mustString($arc, 'id', "archetypes.$i.id");
            $name = $this->mustString($arc, 'name', "archetypes.$i.name");

            // category_weights на уровне архетипа: допускаем разные ключи и любой регистр
            $cw = null;
            if (isset($arc['category_weights']) && is_array($arc['category_weights'])) {
                $cw = $this->normalizeCategoryWeights($arc['category_weights'], "archetypes.$i.category_weights");
            } elseif (isset($arc['weights']) && is_array($arc['weights'])) {
                $cw = $this->normalizeCategoryWeights($arc['weights'], "archetypes.$i.weights");
            } else {
                // если нет — оставим пустой объект; некоторые твои JSON’ы кладут веса только сверху или по умолчанию 1 в одну категорию. :contentReference[oaicite:1]{index=1}
                $cw = [];
            }

            // category: выбираем primary по максимальному весу; если пусто — пробуем явное поле/секцию; иначе "unknown"
            $category = $this->inferPrimaryCategory($cw, $arc);

            // step_duration: из типичных полей о времени (минуты) — typical_length_or_time / typical_answer_length_or_range /
            // numeric_ranges / units "minutes" / и т.п. (best-effort).
            $stepDuration = $this->inferStepDurationMinutes($arc);

            // stem_templates: собираем общие инструкции/шаблоны/фразы (typical_instructions, pattern, question_types-названия и т.д.)
            $stemTemplates = $this->collectStemTemplates($arc);

            // evidence: объединяем evidence (числа/строки) + evidence_sources (url’ы/строки)
            $evidence = $this->collectEvidence($arc);

            // distractors: typical_distractors | common_distractors | distractors
            $distractors = $this->normalizeStringArray(
                $arc['typical_distractors'] ?? $arc['common_distractors'] ?? $arc['distractors'] ?? null,
                "archetypes.$i.distractors",
                allowNull: true
            ) ?? [];

            // ranges: numeric_ranges | numeric_ranges_and_constraints | typical_answer_length_or_range | typical_length_or_time
            $ranges = $this->collectRanges($arc);

            // difficulty: difficulty | difficulty_band | difficulty_band_cefr
            $difficulty = $this->pickFirstString($arc, ['difficulty', 'difficulty_band', 'difficulty_band_cefr']);

            // other: все ключи архетипа, которые мы не использовали — «как есть», без валидации
            $knownKeys = [
                'id','name','category','category_weights','weights','section','pattern','question_types',
                'typical_distractors','common_distractors','distractors','verbs','typical_verbs','common_verbs',
                'units','common_visuals','evidence','evidence_sources','difficulty','difficulty_band','difficulty_band_cefr',
                'typical_answer_length_or_range','typical_length_or_time','numeric_ranges','numeric_ranges_and_constraints',
                'typical_instructions','rationale','description'
            ];
            $other = [];
            foreach ($arc as $k => $v) {
                if (!in_array($k, $knownKeys, true)) {
                    $other[$k] = $v;
                }
            }

            // наполнить category_map
            foreach ($cw as $cat => $w) {
                if (!isset($categoryMap[$cat])) {
                    $categoryMap[$cat] = ['archetype_weights' => []];
                }
                $categoryMap[$cat]['archetype_weights'][] = [
                    'archetype_id' => $id,
                    'weight'       => (float)$w,
                ];
            }

            $globalArchetypes[] = [
                'id'               => $id,
                'name'             => $name,
                'category'         => $category,
                'category_weights' => $cw,
                'step_duration'    => $stepDuration,

                'stem_templates'   => $stemTemplates,
                'evidence'         => $evidence,
                'distractors'      => $distractors,
                'ranges'           => $ranges,
                'difficulty'       => $difficulty,

                'other'            => $other,
            ];
        }

        // total_exam_duration: сумма известных step_duration
        $totalDuration = $this->sumDurations($globalArchetypes);

        // Итог
        return [
            'exam_name'           => $examName,
            'sources'             => $sources,
            'global_archetypes'   => $globalArchetypes,
            'category_map'        => $categoryMap,
            'total_exam_duration' => $totalDuration,
            'rationale'           => $rationale,
        ];
    }

    // ----------------- Helpers -----------------

    private function mustString(array $a, string $key, string $path = null): string
    {
        $p = $path ?: $key;
        if (!isset($a[$key]) || !is_string($a[$key]) || $a[$key] === '') {
            throw ValidationException::withMessages([$p => 'must be non-empty string']);
        }
        return $a[$key];
    }

    private function normalizeSources(mixed $src): array
    {
        if (!is_array($src)) {
            throw ValidationException::withMessages(['sources' => 'sources must be an array']);
        }
        $out = [];
        foreach ($src as $i => $s) {
            if (!is_array($s)) {
                throw ValidationException::withMessages(["sources.$i" => 'must be object']);
            }
            foreach (['url','title','publisher'] as $f) {
                if (!isset($s[$f]) || !is_string($s[$f]) || $s[$f] === '') {
                    throw ValidationException::withMessages(["sources.$i.$f" => 'must be non-empty string']);
                }
            }
            $out[] = ['url'=>$s['url'],'title'=>$s['title'],'publisher'=>$s['publisher']];
        }
        return $out;
    }

    private function normalizeCategoryWeights(array $weights, string $path): array
    {
        $norm = [];
        foreach ($weights as $k => $v) {
            if (!is_string($k) || !is_numeric($v)) {
                throw ValidationException::withMessages(["$path.$k" => 'key must be string, value must be numeric']);
            }
            $norm[strtolower($k)] = (float)$v;
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
        foreach (['category','section','skill','module'] as $k) {
            if (isset($arc[$k]) && is_string($arc[$k]) && $arc[$k] !== '') {
                return strtolower($arc[$k]);
            }
        }
        return 'unknown';
    }

    private function inferStepDurationMinutes(array $arc): ?int
    {
        // typical_length_or_time может быть числом, объектом или массивом; ищем minutes/seconds и т.п. 
        $candidates = [
            'typical_length_or_time',
            'typical_answer_length_or_range',
            'numeric_ranges',
            'numeric_ranges_and_constraints'
        ];
        foreach ($candidates as $k) {
            if (!array_key_exists($k, $arc)) continue;
            $val = $arc[$k];
            $mins = $this->extractMinutes($val);
            if (!is_null($mins)) return $mins;
        }
        // иногда units/description намекают на "minutes" — но без числа надёжно не извлечь. Оставим null. :contentReference[oaicite:3]{index=3}
        return null;
    }

    private function extractMinutes(mixed $v): ?int
    {
        // Число
        if (is_int($v)) return $v;
        if (is_float($v)) return (int)round($v);

        // Строка: ищем "NN minute(s)"
        if (is_string($v)) {
            if (preg_match('/(\d{1,3})\s*min/u', $v, $m)) {
                return (int)$m[1];
            }
            // диапазоны "2–5 minutes" -> берём среднее
            if (preg_match('/(\d{1,3})\s*[–-]\s*(\d{1,3})\s*min/u', $v, $m)) {
                return (int)round(($m[1]+$m[2])/2);
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
                    if (!is_null($mins)) return $mins;
                }
                // альтернативные ключи (monologue_minutes, recording_seconds -> перевод)
                foreach ($v as $key => $val) {
                    if (stripos((string)$key, 'minutes') !== false) {
                        $mins = $this->avgFromArray($val);
                        if (!is_null($mins)) return $mins;
                    }
                    if (stripos((string)$key, 'seconds') !== false) {
                        $secs = $this->avgFromArray($val);
                        if (!is_null($secs)) return (int)round($secs/60);
                    }
                }
            } else {
                // простой список — попробуем найти строку с "minutes"
                foreach ($v as $item) {
                    $mins = $this->extractMinutes($item);
                    if (!is_null($mins)) return $mins;
                }
            }
        }
        return null;
    }

    private function avgFromArray(mixed $val): ?int
    {
        if (is_int($val) || is_float($val)) return (int)round($val);
        if (is_array($val) && !$this->isAssoc($val) && count($val) > 0) {
            $nums = array_values(array_filter($val, fn($x) => is_numeric($x)));
            if ($nums) {
                return (int) round(array_sum($nums)/count($nums));
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
        $out = array_merge($out, array_map(fn($v) => "verb: ".$v, $verbs));

        // уберём дубликаты/пробелы
        $out = array_values(array_unique(array_map('trim', array_filter($out, fn($s) => is_string($s) && $s !== ''))));
        return $out;
    }

    private function collectEvidence(array $arc): array
    {
        $ev = [];

        // индексы/строки
        if (isset($arc['evidence']) && is_array($arc['evidence'])) {
            foreach ($arc['evidence'] as $e) {
                if (is_string($e) || is_int($e)) $ev[] = $e;
            }
        }
        // источники-строки/URL
        if (isset($arc['evidence_sources']) && is_array($arc['evidence_sources'])) {
            foreach ($arc['evidence_sources'] as $e) {
                if (is_string($e)) $ev[] = $e;
            }
        }
        return $ev;
    }

    private function collectRanges(array $arc): mixed
    {
        foreach (['numeric_ranges','numeric_ranges_and_constraints','typical_answer_length_or_range','typical_length_or_time'] as $k) {
            if (array_key_exists($k, $arc)) {
                return $arc[$k]; // как есть
            }
        }
        return null;
    }

    private function pickFirstString(array $arr, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($arr[$k]) && is_string($arr[$k])) return $arr[$k];
        }
        return null;
    }

    private function normalizeStringArray(mixed $value, ?string $path = null, bool $allowNull = false): ?array
    {
        if (is_null($value)) {
            return $allowNull ? null : [];
        }
        if (!is_array($value)) {
            if ($allowNull) return null;
            throw ValidationException::withMessages([$path ?? 'array' => 'must be array of strings']);
        }
        $res = [];
        foreach ($value as $i => $v) {
            if (is_string($v) && $v !== '') $res[] = $v;
        }
        return $res;
    }

    private function sumDurations(array $globalArchetypes): ?int
    {
        $sum = 0; $has = false;
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
        if ($arr === []) return true;
        return array_keys($arr) !== range(0, count($arr)-1);
    }
}
