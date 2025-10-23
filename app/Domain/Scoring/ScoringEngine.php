<?php

namespace App\Domain\Scoring;

use App\Domain\Scoring\Adapters\ExactScoringAdapter;
use App\Domain\Scoring\Adapters\FuzzyScoringAdapter;
use App\Domain\Scoring\Adapters\PartialScoringAdapter;
use App\Domain\Scoring\Adapters\RegexScoringAdapter;
use App\Domain\Scoring\Adapters\RubricScoringAdapter;
use App\Domain\Scoring\Contracts\ScoringAdapter;

final class ScoringEngine
{
    /** @var array<string,ScoringAdapter> */
    private array $adapters;

    public function __construct(array $adapters)
    {
        $this->adapters = $adapters;
    }

    public static function default(): self
    {
        return new self([
            'exact' => new ExactScoringAdapter,
            'partial' => new PartialScoringAdapter,
            'fuzzy' => new FuzzyScoringAdapter,
            'regex' => new RegexScoringAdapter,
            'rubric' => new RubricScoringAdapter,
        ]);
    }

    /**
     * Унифицированный API для тестов/контроллеров:
     *
     * @return array{auto_gradable:bool,total:int,per_item:array}
     */
    public function scoreTask(array $task, array $userMap): array
    {
        $type = (string) ($task['type'] ?? '');
        $mode = (string) ($task['scoring']['mode'] ?? 'exact');

        // Простейшая маршрутизация: если short_answer и заданы regex — используем regex
        if ($type === 'short_answer') {
            if (! empty($task['patterns'] ?? $task['items'][0]['patterns'] ?? null)) {
                $mode = 'regex';
            }
        }

        $adapter = $this->adapters[$mode] ?? $this->adapters['exact'];
        $score = $adapter->score($task, $userMap[$type] ?? $userMap);

        $itemId = (string) ($task['id'] ?? $task['items'][0]['id'] ?? 'item-0');

        return [
            'auto_gradable' => $score->isAuto(),
            'total' => $score->obtained(), // для превью тесты ожидают «score» как «obtained»
            'per_item' => [
                $itemId => ['score' => $score->obtained(), 'total' => $score->total()],
            ],
        ];
    }
}
