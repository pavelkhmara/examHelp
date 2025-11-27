<?php

namespace App\Services\Golden;

use Carbon\Carbon;

class Snapshot
{
    public function __construct(
        public readonly string $examId,
        public readonly string $stage,
        public readonly string $label,
        public readonly string $hash,
        public readonly array $data,
        public readonly Carbon $capturedAt,
        public readonly array $metadata = []
    ) {}

    public function toArray(): array
    {
        return [
            'exam_id' => $this->examId,
            'stage' => $this->stage,
            'label' => $this->label,
            'hash' => $this->hash,
            'data' => $this->data,
            'captured_at' => $this->capturedAt->toIso8601String(),
            'metadata' => $this->metadata,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            examId: $data['exam_id'],
            stage: $data['stage'],
            label: $data['label'],
            hash: $data['hash'],
            data: $data['data'],
            capturedAt: Carbon::parse($data['captured_at']),
            metadata: $data['metadata'] ?? []
        );
    }

    public function getShortHash(): string
    {
        return substr($this->hash, 0, 8);
    }
}
