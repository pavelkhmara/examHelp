<?php

namespace Tests\Unit;

use App\Domain\Scoring\ScoringEngine;
use PHPUnit\Framework\TestCase;

class ScoringEngineTest extends TestCase
{
    public function test_exact_single_select_correct(): void
    {
        $engine = ScoringEngine::default();
        $task = [
            'scoring' => ['mode' => 'exact', 'answer_key' => ['q1' => 'B']],
        ];
        $res = $engine->scoreTask($task, ['q1' => 'B']);
        $this->assertTrue($res['auto_gradable']);
        $this->assertSame(1.0, $res['total']['score']);
        $this->assertSame(1.0, $res['per_item']['q1']['score']);
    }

    public function test_exact_single_select_wrong(): void
    {
        $engine = ScoringEngine::default();
        $task = ['scoring' => ['mode' => 'exact', 'answer_key' => ['q1' => 'B']]];
        $res = $engine->scoreTask($task, ['q1' => 'A']);
        $this->assertSame(0.0, $res['total']['score']);
    }

    public function test_partial_multi_select_with_extra_penalty(): void
    {
        $engine = ScoringEngine::default();
        $task = [
            'scoring' => [
                'mode' => 'partial',
                'answer_key' => ['q2' => ['A', 'C']],
                'partial_rules' => ['per_correct' => 1, 'per_extra' => -1, 'floor' => 0],
            ],
        ];
        $res = $engine->scoreTask($task, ['q2' => ['A', 'B', 'C']]); // 2 correct + 1 extra => (2*1 + 1*(-1)) / (2*1) = 0.5
        $this->assertEqualsWithDelta(0.5, $res['per_item']['q2']['score'], 1e-6);
    }

    public function test_regex_short_answer_any_of_patterns(): void
    {
        $engine = ScoringEngine::default();
        $task = [
            'scoring' => [
                'mode' => 'regex',
                'answer_key' => ['q3' => ['/^(blue|azure)$/i', '/^royal blue$/i']],
            ],
        ];
        $res = $engine->scoreTask($task, ['q3' => 'Azure']);
        $this->assertSame(1.0, $res['per_item']['q3']['score']);
    }

    public function test_fuzzy_short_answer_threshold(): void
    {
        $engine = ScoringEngine::default();
        $task = [
            'scoring' => [
                'mode' => 'fuzzy',
                'answer_key' => ['q4' => 'accommodation'],
                'threshold' => 0.8,
            ],
        ];
        $res1 = $engine->scoreTask($task, ['q4' => 'acommodation']); // common misspelling
        $this->assertSame(1.0, $res1['per_item']['q4']['score']);
        $res2 = $engine->scoreTask($task, ['q4' => 'house']);
        $this->assertSame(0.0, $res2['per_item']['q4']['score']);
    }

    public function test_rubric_non_auto_gradable(): void
    {
        $engine = ScoringEngine::default();
        $task = ['scoring' => ['mode' => 'rubric', 'rubric' => ['bands' => [['min' => 0, 'max' => 5]]]]];
        $res = $engine->scoreTask($task, ['qX' => 'anything']);
        $this->assertFalse($res['auto_gradable']);
    }
}
