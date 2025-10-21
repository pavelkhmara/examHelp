<?php

namespace App\Domain\Scoring;

interface ScoringAdapterInterface
{
    /**
     * @param array<string,mixed> $payload   // canonical ground truth
     * @param array<string,mixed>|string|int|float $userAnswer
     */
    public function score(array $payload, $userAnswer): Score;
}
