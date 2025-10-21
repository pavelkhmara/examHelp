<?php

namespace Tests\Unit;

use App\Domain\Questions\Validation\QuestionPayloadValidator;
use App\Domain\Scoring\ScoringEngine;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class QuestionValidationAndScoringTest extends TestCase
{
    public function test_single_select_valid(): void
    {
        $type = 'single_select';
        $payload = [
            'options' => [
                ['id'=>'a','text'=>'A'],
                ['id'=>'b','text'=>'B'],
            ],
            'answer' => 'a',
        ];
        $this->assertNull(QuestionPayloadValidator::validate($type, $payload));
    }

    public function test_single_select_invalid_missing_answer(): void
    {
        $this->expectException(ValidationException::class);
        QuestionPayloadValidator::validate('single_select', [
            'options' => [['id'=>'a','text'=>'A'],['id'=>'b','text'=>'B']],
        ]);
    }

    public function test_multi_select_valid(): void
    {
        $type = 'multi_select';
        $payload = [
            'options' => [['id'=>'a','text'=>'A'],['id'=>'b','text'=>'B'],['id'=>'c','text'=>'C']],
            'answers' => ['a','c'],
        ];
        QuestionPayloadValidator::validate($type, $payload);
        $engine = new ScoringEngine();
        $score = $engine->score($type, $payload, ['a','b'])->toArray();
        $this->assertGreaterThan(0.0, $score['score']); // частично верно
        $this->assertLessThan(1.0, $score['score']);
    }

    public function test_multi_select_invalid_answer_not_in_options(): void
    {
        $this->expectException(ValidationException::class);
        QuestionPayloadValidator::validate('multi_select', [
            'options' => [['id'=>'a','text'=>'A'],['id'=>'b','text'=>'B']],
            'answers' => ['x'],
        ]);
    }

    public function test_true_false_valid(): void
    {
        QuestionPayloadValidator::validate('true_false', ['answer'=>'true']);
    }

    public function test_highlight_text_spans(): void
    {
        QuestionPayloadValidator::validate('highlight_text', [
            'spans' => [['start'=>0,'end'=>3],['start'=>5,'end'=>8]],
        ]);
        $this->expectException(ValidationException::class);
        QuestionPayloadValidator::validate('highlight_text', [
            'spans' => [['start'=>5,'end'=>5]],
        ]);
    }

    public function test_order_words_partial_scoring(): void
    {
        $payload = ['order'=>['w1','w2','w3','w4']];
        QuestionPayloadValidator::validate('order_words', $payload);

        $engine = new ScoringEngine();
        $score = $engine->score('order_words', $payload, ['w1','w3','w2','w4'])->toArray();
        $this->assertGreaterThan(0.0, $score['score']);
        $this->assertLessThan(1.0, $score['score']);
    }

    public function test_short_answer_fuzzy_scoring(): void
    {
        $payload = ['answers'=>['a1'=>'hello']];
        QuestionPayloadValidator::validate('short_answer', $payload);

        $engine = new ScoringEngine();
        $score = $engine->score('short_answer', $payload, 'Hello!')->toArray();
        $this->assertGreaterThan(0.5, $score['score']); // похожее
    }

    public function test_rubric_scoring(): void
    {
        $payload = ['rubric'=>[
            ['id'=>'content','weight'=>0.5,'score'=>0.8],
            ['id'=>'grammar','weight'=>0.5,'score'=>0.6],
        ]];
        $engine = new ScoringEngine();
        $score = $engine->score('writing_prompt', $payload, null)->toArray();
        $this->assertEqualsWithDelta(0.7, $score['score'], 0.001);
    }

    public function test_unknown_type_rejected(): void
    {
        $this->expectException(ValidationException::class);
        QuestionPayloadValidator::validate('not_a_valid_type', []);
    }
}
