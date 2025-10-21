<?php
declare(strict_types=1);

namespace App\Domain\Scoring\Adapters;

use App\Domain\Scoring\Contracts\ScoringAdapter;
use App\Domain\Scoring\Score;

/**
 * FuzzyScoringAdapter — строковая похожесть (0..1) по лучшему совпадению.
 */
final class FuzzyScoringAdapter implements ScoringAdapter
{
    /**
     * @param array<string,mixed> $payload
     * @param mixed $userAnswer
     */
    public function score(array $payload, mixed $userAnswer): Score
    {
        $ua = (string)$userAnswer;
        $best = 0.0;

        if (\array_key_exists('answers', $payload) && \is_array($payload['answers'])) {
            foreach ($payload['answers'] as $exp) {
                $best = \max($best, $this->similarity($ua, (string)$exp));
            }
        } elseif (\array_key_exists('answer', $payload)) {
            $best = $this->similarity($ua, (string)$payload['answer']);
        }

        return new Score(true, $best, []);
    }

    private function similarity(string $a, string $b): float
    {
        if ($a === '' && $b === '') return 1.0;
        if ($a === '' || $b === '') return 0.0;

        $a = mb_strtolower($a);
        $b = mb_strtolower($b);
        $dist = levenshtein($a, $b);
        $maxLen = max(mb_strlen($a), mb_strlen($b));
        return 1.0 - ($dist / max(1, $maxLen));
    }
}
