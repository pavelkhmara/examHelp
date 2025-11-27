<?php

namespace App\Services\Golden;

class ComparisonResult
{
    public function __construct(
        public readonly float $similarity,
        public readonly bool $passed,
        public readonly array $criticalMatches,
        public readonly array $flexibleMatches,
        public readonly array $diffs,
        public readonly array $metadata = []
    ) {}

    public function toArray(): array
    {
        return [
            'similarity' => $this->similarity,
            'passed' => $this->passed,
            'critical_matches' => $this->criticalMatches,
            'flexible_matches' => $this->flexibleMatches,
            'diffs' => $this->diffs,
            'metadata' => $this->metadata,
        ];
    }

    public function getSummary(): string
    {
        $status = $this->passed ? '✓ PASSED' : '✗ FAILED';
        $pct = round($this->similarity * 100, 1);

        return "{$status} (similarity: {$pct}%)";
    }

    public function getReport(): string
    {
        $lines = [];
        $lines[] = $this->getSummary();
        $lines[] = '';

        // Critical mismatches
        $criticalFails = array_filter($this->criticalMatches, fn($m) => !$m['match']);
        if (!empty($criticalFails)) {
            $lines[] = 'Critical field mismatches:';
            foreach ($criticalFails as $field => $match) {
                $lines[] = "  ✗ {$field}";
                $lines[] = '    Generated: '.json_encode($match['generated'], JSON_UNESCAPED_UNICODE);
                $lines[] = '    Golden:    '.json_encode($match['golden'], JSON_UNESCAPED_UNICODE);
            }
            $lines[] = '';
        }

        // Top differences
        if (!empty($this->diffs)) {
            $lines[] = 'Differences ('.count($this->diffs).' total):';
            foreach (array_slice($this->diffs, 0, 10) as $diff) {
                $lines[] = "  - [{$diff['type']}] {$diff['path']}";
            }
            if (count($this->diffs) > 10) {
                $remaining = count($this->diffs) - 10;
                $lines[] = "  ... and {$remaining} more";
            }
        }

        return implode("\n", $lines);
    }

    public function getCriticalScore(): float
    {
        if (empty($this->criticalMatches)) {
            return 1.0;
        }

        $passed = count(array_filter($this->criticalMatches, fn($m) => $m['match']));

        return $passed / count($this->criticalMatches);
    }
}
