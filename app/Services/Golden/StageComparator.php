<?php

namespace App\Services\Golden;

class StageComparator
{
    private float $defaultThreshold = 0.8;

    public function compare(array $generated, array $golden, array $options = []): ComparisonResult
    {
        $threshold = $options['threshold'] ?? $this->defaultThreshold;
        $hints = $golden['_comparison_hints'] ?? [];

        // 1. Сравнить critical fields (должны совпадать)
        $criticalFields = $hints['critical_fields'] ?? [];
        $criticalMatches = $this->compareCriticalFields($generated, $golden, $criticalFields);

        // 2. Сравнить flexible fields (допускаются вариации)
        $flexibleFields = $hints['flexible_fields'] ?? [];
        $flexibleMatches = $this->compareFlexibleFields($generated, $golden, $flexibleFields);

        // 3. Собрать все различия
        $ignoreFields = array_merge(
            $hints['ignore_fields'] ?? [],
            ['$schema', 'version', '_comparison_hints', '_notes', '_reference', '_source', 'exam_id', 'created_at']
        );
        $diffs = $this->collectDiffs($generated, $golden, $ignoreFields);

        // 4. Рассчитать similarity
        $similarity = $this->calculateSimilarity($criticalMatches, $flexibleMatches, $diffs);

        return new ComparisonResult(
            similarity: $similarity,
            passed: $similarity >= $threshold,
            criticalMatches: $criticalMatches,
            flexibleMatches: $flexibleMatches,
            diffs: $diffs,
            metadata: [
                'threshold' => $threshold,
                'stage' => $golden['stage'] ?? 'unknown',
                'exam_id' => $golden['exam_id'] ?? 'unknown',
            ]
        );
    }

    public function similarity(array $generated, array $golden): float
    {
        return $this->compare($generated, $golden)->similarity;
    }

    public function diff(array $generated, array $golden): array
    {
        return $this->compare($generated, $golden)->diffs;
    }

    /**
     * Сравнить критические поля (точное совпадение)
     */
    private function compareCriticalFields(array $generated, array $golden, array $fields): array
    {
        $results = [];

        foreach ($fields as $fieldPath) {
            $genValue = $this->getByPath($generated, $fieldPath);
            $goldValue = $this->getByPath($golden, $fieldPath);

            $results[$fieldPath] = [
                'match' => $this->valuesMatch($genValue, $goldValue),
                'generated' => $genValue,
                'golden' => $goldValue,
            ];
        }

        return $results;
    }

    /**
     * Сравнить гибкие поля (допускаются вариации)
     */
    private function compareFlexibleFields(array $generated, array $golden, array $fields): array
    {
        $results = [];

        foreach ($fields as $fieldPath) {
            $genValue = $this->getByPath($generated, $fieldPath);
            $goldValue = $this->getByPath($golden, $fieldPath);

            // Для гибких полей используем fuzzy matching
            $similarity = $this->fuzzyCompare($genValue, $goldValue);

            $results[$fieldPath] = [
                'similarity' => $similarity,
                'generated_preview' => $this->preview($genValue),
                'golden_preview' => $this->preview($goldValue),
            ];
        }

        return $results;
    }

    /**
     * Собрать все различия рекурсивно
     */
    private function collectDiffs(array $generated, array $golden, array $ignore, string $path = ''): array
    {
        $diffs = [];

        // Пройти по golden
        foreach ($golden as $key => $goldValue) {
            $currentPath = $path ? "{$path}.{$key}" : $key;

            if (in_array($key, $ignore) || in_array($currentPath, $ignore)) {
                continue;
            }

            // Пропускаем служебные поля
            if (str_starts_with($key, '_')) {
                continue;
            }

            if (! array_key_exists($key, $generated)) {
                $diffs[] = [
                    'type' => 'missing_in_generated',
                    'path' => $currentPath,
                    'golden' => $this->preview($goldValue),
                ];

                continue;
            }

            $genValue = $generated[$key];

            if (is_array($goldValue) && is_array($genValue)) {
                // Проверяем, это индексированный массив или ассоциативный
                if ($this->isIndexedArray($goldValue) && $this->isIndexedArray($genValue)) {
                    // Сравниваем длину массивов
                    if (count($goldValue) !== count($genValue)) {
                        $diffs[] = [
                            'type' => 'array_length_mismatch',
                            'path' => $currentPath,
                            'generated' => count($genValue),
                            'golden' => count($goldValue),
                        ];
                    }
                    // Рекурсивно сравниваем элементы
                    $minLen = min(count($goldValue), count($genValue));
                    for ($i = 0; $i < $minLen; $i++) {
                        if (is_array($goldValue[$i]) && is_array($genValue[$i])) {
                            $diffs = array_merge(
                                $diffs,
                                $this->collectDiffs($genValue[$i], $goldValue[$i], $ignore, "{$currentPath}[{$i}]")
                            );
                        } elseif ($goldValue[$i] !== $genValue[$i]) {
                            $diffs[] = [
                                'type' => 'value_mismatch',
                                'path' => "{$currentPath}[{$i}]",
                                'generated' => $this->preview($genValue[$i]),
                                'golden' => $this->preview($goldValue[$i]),
                            ];
                        }
                    }
                } else {
                    // Ассоциативный массив — рекурсивно
                    $diffs = array_merge($diffs, $this->collectDiffs($genValue, $goldValue, $ignore, $currentPath));
                }
            } elseif ($goldValue !== $genValue) {
                $diffs[] = [
                    'type' => 'value_mismatch',
                    'path' => $currentPath,
                    'generated' => $this->preview($genValue),
                    'golden' => $this->preview($goldValue),
                ];
            }
        }

        // Проверить extra keys в generated
        foreach ($generated as $key => $genValue) {
            $currentPath = $path ? "{$path}.{$key}" : $key;

            if (in_array($key, $ignore) || in_array($currentPath, $ignore)) {
                continue;
            }

            if (str_starts_with($key, '_')) {
                continue;
            }

            if (! array_key_exists($key, $golden)) {
                $diffs[] = [
                    'type' => 'extra_in_generated',
                    'path' => $currentPath,
                    'generated' => $this->preview($genValue),
                ];
            }
        }

        return $diffs;
    }

    /**
     * Рассчитать общую similarity
     */
    private function calculateSimilarity(array $criticalMatches, array $flexibleMatches, array $diffs): float
    {
        // Critical fields имеют вес 0.5
        $criticalScore = 1.0;
        $criticalCount = count($criticalMatches);
        if ($criticalCount > 0) {
            $criticalPassed = count(array_filter($criticalMatches, fn ($m) => $m['match']));
            $criticalScore = $criticalPassed / $criticalCount;
        }

        // Flexible fields имеют вес 0.3
        $flexibleScore = 1.0;
        $flexibleCount = count($flexibleMatches);
        if ($flexibleCount > 0) {
            $flexibleSum = array_sum(array_column($flexibleMatches, 'similarity'));
            $flexibleScore = $flexibleSum / $flexibleCount;
        }

        // Diffs штрафуют общий score (вес 0.2)
        $diffPenalty = min(1.0, count($diffs) * 0.02); // Каждый diff -2%
        $diffScore = 1.0 - $diffPenalty;

        return ($criticalScore * 0.5) + ($flexibleScore * 0.3) + ($diffScore * 0.2);
    }

    /**
     * Получить значение по dot-notation пути с поддержкой []
     */
    private function getByPath(array $data, string $path): mixed
    {
        // Поддержка sections[].skill формата
        if (str_contains($path, '[]')) {
            return $this->getByPathWithArrays($data, $path);
        }

        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            // Поддержка [0], [1] нотации
            if (preg_match('/^(.+)\[(\d+)\]$/', $key, $matches)) {
                $key = $matches[1];
                $index = (int) $matches[2];

                if (! isset($value[$key]) || ! is_array($value[$key])) {
                    return null;
                }
                $value = $value[$key][$index] ?? null;

                continue;
            }

            if (! is_array($value) || ! isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Получить массив значений для путей с []
     * Например: sections[].skill вернёт [listening, reading, writing, ...]
     */
    private function getByPathWithArrays(array $data, string $path): array
    {
        $parts = explode('[].', $path, 2);
        if (count($parts) !== 2) {
            return [];
        }

        $arrayPath = $parts[0];
        $fieldPath = $parts[1];

        $array = $this->getByPath($data, $arrayPath);
        if (! is_array($array)) {
            return [];
        }

        $results = [];
        foreach ($array as $item) {
            if (is_array($item)) {
                $value = $this->getByPath($item, $fieldPath);
                if ($value !== null) {
                    $results[] = $value;
                }
            }
        }

        return $results;
    }

    private function valuesMatch(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }

        // Сравнение массивов (игнорируем порядок для простых значений)
        if (is_array($a) && is_array($b)) {
            // Для индексированных массивов простых значений — сравниваем как множества
            if ($this->isSimpleIndexedArray($a) && $this->isSimpleIndexedArray($b)) {
                sort($a);
                sort($b);

                return $a === $b;
            }

            return $a == $b;
        }

        return false;
    }

    private function fuzzyCompare(mixed $a, mixed $b): float
    {
        if ($a === $b) {
            return 1.0;
        }
        if ($a === null || $b === null) {
            return 0.0;
        }

        if (is_string($a) && is_string($b)) {
            similar_text($a, $b, $percent);

            return $percent / 100;
        }

        if (is_numeric($a) && is_numeric($b)) {
            $max = max(abs($a), abs($b));
            if ($max == 0) {
                return 1.0;
            }

            return 1.0 - abs($a - $b) / $max;
        }

        if (is_array($a) && is_array($b)) {
            // Сравнить длину массивов
            $lenA = count($a);
            $lenB = count($b);
            if ($lenA === 0 && $lenB === 0) {
                return 1.0;
            }
            if ($lenA === 0 || $lenB === 0) {
                return 0.0;
            }

            return 1.0 - abs($lenA - $lenB) / max($lenA, $lenB);
        }

        return 0.0;
    }

    private function preview(mixed $value, int $maxLen = 50): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            if ($this->isIndexedArray($value)) {
                return 'Array('.count($value).')';
            }

            return 'Object{'.implode(',', array_slice(array_keys($value), 0, 3)).'}';
        }

        $str = (string) $value;
        if (strlen($str) > $maxLen) {
            return substr($str, 0, $maxLen).'...';
        }

        return $str;
    }

    private function isIndexedArray(array $arr): bool
    {
        if (empty($arr)) {
            return true;
        }

        return array_keys($arr) === range(0, count($arr) - 1);
    }

    private function isSimpleIndexedArray(array $arr): bool
    {
        if (! $this->isIndexedArray($arr)) {
            return false;
        }

        foreach ($arr as $item) {
            if (is_array($item) || is_object($item)) {
                return false;
            }
        }

        return true;
    }
}
