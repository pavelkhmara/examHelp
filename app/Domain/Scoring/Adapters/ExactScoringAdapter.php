<?php
declare(strict_types=1);

namespace App\Domain\Scoring\Adapters;

use App\Domain\Scoring\Contracts\ScoringAdapter;
use App\Domain\Scoring\Score;

/**
 * ExactScoringAdapter — полное совпадение.
 * Возвращает auto_gradable=true, total ∈ [0..1].
 */
final class ExactScoringAdapter implements ScoringAdapter
{
    /**
     * @param array<string,mixed> $payload
     * @param mixed $userAnswer
     */
    public function score(array $payload, mixed $userAnswer): Score
    {
        // Одиночный ответ (строка/число/булево)
        if (\array_key_exists('answer', $payload)) {
            $expected = $payload['answer'];
            $ok = $this->scalarEqual($userAnswer, $expected);
            return new Score(true, $ok ? 1.0 : 0.0, []);
        }

        // Набор ответов (мультиселект, сопоставления и т.п.)
        if (\array_key_exists('answers', $payload) && \is_array($payload['answers'])) {
            $expected = $payload['answers'];
            $perItem = [];

            if (\is_array($userAnswer)) {
                // item-wise точность, если ответы ассоц.массивом
                foreach ($expected as $key => $val) {
                    $hit = (isset($userAnswer[$key]) && $this->scalarEqual($userAnswer[$key], $val)) ? 1.0 : 0.0;
                    $perItem[] = $hit;
                }
            }

            $total = 0.0;
            if ($perItem !== []) {
                $total = \array_sum($perItem) / \max(1, \count($perItem));
            } else {
                // иначе — строгое равенство массивов
                $total = ($this->arrayLooseEqual($userAnswer, $expected)) ? 1.0 : 0.0;
            }

            return new Score(true, $total, $perItem);
        }

        // Фоллбэк
        return new Score(true, 0.0, []);
    }

    private function scalarEqual($a, $b): bool
    {
        if (\is_bool($a) || \is_bool($b)) {
            return (bool)$a === (bool)$b;
        }
        return (string)$a === (string)$b;
    }

    /**
     * "Мягкое" сравнение массивов (без учёта порядка для списков).
     * @param mixed $given
     * @param mixed $expected
     */
    private function arrayLooseEqual($given, $expected): bool
    {
        if (!\is_array($given) || !\is_array($expected)) {
            return false;
        }
        $isList = static fn(array $arr): bool => array_keys($arr) === range(0, count($arr) - 1);

        if ($isList($given) && $isList($expected)) {
            $a = $given; $b = $expected;
            sort($a); sort($b);
            return $a === $b;
        }

        ksort($given); ksort($expected);
        return $given === $expected;
    }
}
