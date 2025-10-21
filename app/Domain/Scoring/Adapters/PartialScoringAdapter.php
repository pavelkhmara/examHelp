<?php
declare(strict_types=1);

namespace App\Domain\Scoring\Adapters;

use App\Domain\Scoring\Contracts\ScoringAdapter;
use App\Domain\Scoring\Score;

/**
 * PartialScoringAdapter — частичные кредиты:
 * - multi_select: доля угаданных из ожидаемых;
 * - matching: по ключам левый→правый;
 * - order_words/order_sentences: позиционная точность;
 * - highlight_text: доля отмеченных ожидаемых сегментов.
 */
final class PartialScoringAdapter implements ScoringAdapter
{
    /**
     * @param array<string,mixed> $payload
     * @param mixed $userAnswer
     */
    public function score(array $payload, mixed $userAnswer): Score
    {
        $type = (string)($payload['type'] ?? '');

        // multi_select
        if ($type === 'multi_select') {
            $expected = (array)($payload['answers'] ?? []);
            $given = (array)$userAnswer;

            $expSet = array_values(array_unique(array_map('strval', $expected)));
            $givSet = array_values(array_unique(array_map('strval', $given)));

            $hits = count(array_intersect($expSet, $givSet));
            $total = count($expSet) > 0 ? $hits / count($expSet) : 0.0;

            $perItem = [];
            foreach ($expSet as $v) {
                $perItem[] = in_array($v, $givSet, true) ? 1.0 : 0.0;
            }

            return new Score(true, $total, $perItem);
        }

        // matching (ожидаем ассоц.массив answers)
        if ($type === 'matching') {
            $expected = (array)($payload['answers'] ?? []);
            $given = (array)$userAnswer;

            $keys = array_unique(array_merge(array_keys($expected), array_keys($given)));
            $correct = 0;
            $perItem = [];

            foreach ($keys as $k) {
                $ok = isset($expected[$k], $given[$k]) && (string)$expected[$k] === (string)$given[$k];
                $perItem[] = $ok ? 1.0 : 0.0;
                if ($ok) $correct++;
            }

            $total = \count($keys) > 0 ? $correct / \count($keys) : 0.0;
            return new Score(true, $total, $perItem);
        }

        // order_* (позиции)
        if ($type === 'order_words' || $type === 'order_sentences') {
            // контракт: ожидаемый порядок лежит в payload.order (а не answers)
            $expected = array_values((array)($payload['order'] ?? $payload['answers'] ?? []));
            $given = array_values((array)$userAnswer);
        
            $n = max(count($expected), count($given));
            if ($n === 0) {
                return new Score(true, 0.0, []);
            }
        
            $pos = [];
            foreach ($expected as $i => $token) {
                $pos[(string)$token] = $i;
            }
        
            $perItem = [];
            $correctPos = 0;
            foreach ($given as $i => $token) {
                $tok = (string)$token;
                if (array_key_exists($tok, $pos)) {
                    $ok = ($pos[$tok] === $i);
                    $perItem[] = $ok ? 1.0 : 0.0;
                    if ($ok) $correctPos++;
                } else {
                    $perItem[] = 0.0;
                }
            }
        
            $total = $n > 0 ? $correctPos / $n : 0.0;
            return new Score(true, $total, $perItem);
        }

        // highlight_text
        if ($type === 'highlight_text') {
            $expected = array_values((array)($payload['answers'] ?? [])); // набор индексов/тегов
            $given = array_values((array)$userAnswer);

            $expSet = array_values(array_unique(array_map('strval', $expected)));
            $givSet = array_values(array_unique(array_map('strval', $given)));

            $hits = count(array_intersect($expSet, $givSet));
            $total = count($expSet) > 0 ? $hits / count($expSet) : 0.0;

            $perItem = [];
            foreach ($expSet as $v) {
                $perItem[] = in_array($v, $givSet, true) ? 1.0 : 0.0;
            }

            return new Score(true, $total, $perItem);
        }

        // Фоллбэк
        return new Score(true, 0.0, []);
    }
}
