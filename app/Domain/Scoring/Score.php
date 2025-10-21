<?php
declare(strict_types=1);

namespace App\Domain\Scoring;

/**
 * Результат автопроверки.
 * Совместимо с ожидаемым контрактом тестов: ключ 'score' присутствует.
 */
final class Score
{
    /**
     * @param array<int,int|float> $per_item
     */
    public function __construct(
        public readonly bool $auto_gradable,
        public readonly int|float $total,
        public readonly array $per_item = [],
    ) {}

    /** Для совместимости с тестами: вернуть и 'score', и 'total' */
    public function toArray(): array
    {
        return [
            'auto_gradable' => $this->auto_gradable,
            'score'         => $this->total,    // alias
            'total'         => $this->total,
            'per_item'      => $this->per_item,
        ];
    }
}
