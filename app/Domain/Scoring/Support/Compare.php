<?php

namespace App\Domain\Scoring\Support;

final class Compare
{
    public static function normalizeScalar(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_numeric($v)) {
            return (string) $v;
        }
        $s = is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);

        return mb_strtolower(trim((string) $s));
    }

    public static function isAssoc(array $a): bool
    {
        return array_keys($a) !== range(0, count($a) - 1);
    }

    /** Deep equality with set-compare for list arrays and key-compare for assoc arrays */
    public static function deepEqual(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            $assocA = self::isAssoc($a);
            $assocB = self::isAssoc($b);
            if ($assocA || $assocB) {
                if ($assocA !== $assocB) {
                    return false;
                }
                ksort($a);
                ksort($b);
                foreach ($a as $k => $v) {
                    if (! array_key_exists($k, $b)) {
                        return false;
                    }
                    if (! self::deepEqual($v, $b[$k])) {
                        return false;
                    }
                }

                return count($a) === count($b);
            } else {
                $na = array_map([self::class, 'normalizeScalar'], $a);
                $nb = array_map([self::class, 'normalizeScalar'], $b);
                sort($na);
                sort($nb);

                return $na === $nb;
            }
        }

        return self::normalizeScalar($a) === self::normalizeScalar($b);
    }

    public static function fuzzyMatch(string $a, string $b, float $threshold = 0.8): bool
    {
        $a = self::normalizeScalar($a);
        $b = self::normalizeScalar($b);
        if ($a === '' && $b === '') {
            return true;
        }
        $dist = levenshtein($a, $b);
        $max = max(mb_strlen($a), mb_strlen($b));
        $sim = $max > 0 ? 1 - ($dist / $max) : 1.0;

        return $sim >= $threshold;
    }
}
