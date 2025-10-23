<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Scoring\ScoringEngine;
use PHPUnit\Framework\TestCase;

final class ScoringSmokeTest extends TestCase
{
    public function test_single_select_exact(): void
    {
        $engine = ScoringEngine::default();

        $task = [
            'type' => 'single_select',
            'items' => [[
                'id' => 'q1',
                'prompt' => 'Capital of France?',
                'options' => [
                    ['id' => 'a', 'label' => 'Paris', 'is_correct' => true],
                    ['id' => 'b', 'label' => 'Rome'],
                ],
            ]],
            'scoring' => ['mode' => 'exact'],
        ];
        $res = $engine->scoreTask($task, ['single_select' => ['selected_option_id' => 'a']]);
        self::assertTrue($res['auto_gradable']);
        self::assertSame(1, $res['total']);
    }
}
