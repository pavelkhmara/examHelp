<?php

namespace Tests\Unit;

use App\Domain\Scoring\Adapters\RegexScoringAdapter;
use PHPUnit\Framework\TestCase;

final class RegexScoringAdapterTest extends TestCase
{
    public function test_item_level_patterns(): void
    {
        $adapter = new RegexScoringAdapter;

        $payload = ['mode' => 'regex', 'patterns' => ['^hel+o$', 'world']];
        $score = $adapter->score($payload, 'HeLLo'); // регистр игнорится по умолчанию

        $this->assertSame(1, $score->total);
        $this->assertSame(1, $score->max);
        $this->assertTrue($score->autoGradable);
        $this->assertSame(['item' => ['score' => 1, 'max' => 1]], $score->perItem);
    }

    public function test_task_level_answer_key(): void
    {
        $adapter = new RegexScoringAdapter;

        $payload = [
            'mode' => 'regex',
            'answer_key' => [
                'q1' => ['patterns' => ['foo']],
                'q2' => ['patterns' => ['bar']],
            ],
        ];
        $user = ['q1' => 'foo!', 'q2' => 'nope'];

        $score = $adapter->score($payload, $user);

        $this->assertSame(1, $score->total);
        $this->assertSame(2, $score->max);
        $this->assertArrayHasKey('q1', $score->perItem);
        $this->assertArrayHasKey('q2', $score->perItem);
    }
}
