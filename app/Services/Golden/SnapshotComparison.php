<?php

namespace App\Services\Golden;

class SnapshotComparison
{
    public function __construct(
        public readonly bool $hasBaseline,
        public readonly array $current,
        public readonly ?Snapshot $baseline,
        public readonly float $similarity,
        public readonly array $diffs,
        public readonly string $message
    ) {}

    public function isPassing(float $threshold = 0.8): bool
    {
        return $this->hasBaseline && $this->similarity >= $threshold;
    }

    public function getReport(): string
    {
        $lines = [];
        $lines[] = '=== Snapshot Comparison Report ===';
        $lines[] = '';

        if (! $this->hasBaseline) {
            $lines[] = 'Status: NO BASELINE';
            $lines[] = "Message: {$this->message}";

            return implode("\n", $lines);
        }

        $status = $this->isPassing() ? '✓ PASS' : '✗ FAIL';
        $pct = round($this->similarity * 100, 1);

        $lines[] = "Status: {$status}";
        $lines[] = "Similarity: {$pct}%";
        $lines[] = "Baseline: {$this->baseline->label} (captured: {$this->baseline->capturedAt->format('Y-m-d H:i')})";
        $lines[] = "Hash: {$this->baseline->getShortHash()}";
        $lines[] = '';

        if (! empty($this->diffs)) {
            $lines[] = 'Differences ('.count($this->diffs).' total):';
            foreach (array_slice($this->diffs, 0, 10) as $diff) {
                $type = $diff['type'] ?? 'unknown';
                $path = $diff['path'] ?? '';
                $lines[] = "  - [{$type}] {$path}";
            }
            if (count($this->diffs) > 10) {
                $remaining = count($this->diffs) - 10;
                $lines[] = "  ... and {$remaining} more";
            }
        } else {
            $lines[] = 'No differences found.';
        }

        return implode("\n", $lines);
    }

    public function toArray(): array
    {
        return [
            'has_baseline' => $this->hasBaseline,
            'similarity' => $this->similarity,
            'passed' => $this->isPassing(),
            'diffs_count' => count($this->diffs),
            'baseline_label' => $this->baseline?->label,
            'baseline_hash' => $this->baseline?->getShortHash(),
            'message' => $this->message,
        ];
    }
}
