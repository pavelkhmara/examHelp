<?php

namespace App\Domain\Scoring;

final class Score
{
    public function __construct(
        private int $total,      // суммарные баллы за айтем (или набор)
        private int $obtained,   // набранные
        private bool $autoGradable = true,
        private array $perItem = [] // для агрегатов (id=>[score,total])
    ) {}

    public function total(): int
    {
        return $this->total;
    }

    public function obtained(): int
    {
        return $this->obtained;
    }

    public function isAuto(): bool
    {
        return $this->autoGradable;
    }

    public function breakdown(): array
    {
        return $this->perItem;
    }
}
