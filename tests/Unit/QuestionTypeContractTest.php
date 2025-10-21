<?php

namespace Tests\Unit;

use App\Services\LanguageApp\Validators\QuestionTypeContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QuestionTypeContractTest extends TestCase
{
    public function test_single_select_exact_ok(): void
    {
        $v = new QuestionTypeContract;
        $ok = $v->validateTask([
            'type' => 'single_select',
            'items' => [[
                'id' => 'q1', 'prompt' => 'P?', 'options' => [['id' => 'A', 'label' => 'a'], ['id' => 'B', 'label' => 'b']],
            ]],
            'scoring' => ['mode' => 'exact', 'answer_key' => ['q1' => 'A']],
        ]);
        $this->assertSame('single_select', $ok['type']);
    }

    public function test_unknown_type_rejected(): void
    {
        $this->expectException(ValidationException::class);
        (new QuestionTypeContract)->validateTask([
            'type' => 'weird_type', 'items' => [['id' => 'q1', 'prompt' => '?']], 'scoring' => ['mode' => 'exact', 'answer_key' => []],
        ]);
    }

    public function test_multi_select_partial_allowed(): void
    {
        $ok = (new QuestionTypeContract)->validateTask([
            'type' => 'multi_select',
            'items' => [[
                'id' => 'q2', 'prompt' => 'P2', 'options' => [['id' => 'A', 'label' => 'a'], ['id' => 'B', 'label' => 'b']],
            ]],
            'scoring' => ['mode' => 'partial', 'answer_key' => ['q2' => ['A']]],
        ]);
        $this->assertSame('partial', $ok['scoring']['mode']);
    }

    public function test_true_false_requires_exact(): void
    {
        $this->expectException(ValidationException::class);
        (new QuestionTypeContract)->validateTask([
            'type' => 'true_false', 'items' => [['id' => 'q', 'prompt' => '?']], 'scoring' => ['mode' => 'partial', 'answer_key' => ['q' => 'true']],
        ]);
    }

    public function test_dropdown_cloze_needs_options(): void
    {
        $this->expectException(ValidationException::class);
        (new QuestionTypeContract)->validateTask([
            'type' => 'dropdown_cloze', 'items' => [['id' => 'q', 'prompt' => '?']], 'scoring' => ['mode' => 'exact', 'answer_key' => ['q' => 'x']],
        ]);
    }

    public function test_gap_cloze_allows_fuzzy(): void
    {
        $ok = (new QuestionTypeContract)->validateTask([
            'type' => 'gap_cloze',
            'items' => [['id' => 'q', 'prompt' => '?', 'slots' => ['s1']]], 'scoring' => ['mode' => 'fuzzy', 'answer_key' => ['q' => ['s1' => 'blue']]],
        ]);
        $this->assertSame('fuzzy', $ok['scoring']['mode']);
    }

    public function test_matching_needs_pairs(): void
    {
        $this->expectException(ValidationException::class);
        (new QuestionTypeContract)->validateTask([
            'type' => 'matching', 'items' => [['id' => 'q', 'prompt' => '?']], 'scoring' => ['mode' => 'exact', 'answer_key' => []],
        ]);
    }

    public function test_order_sentences_partial_ok(): void
    {
        $ok = (new QuestionTypeContract)->validateTask([
            'type' => 'order_sentences', 'items' => [['id' => 'q', 'prompt' => '?', 'order' => ['a', 'b', 'c']]], 'scoring' => ['mode' => 'partial', 'answer_key' => ['q' => ['a', 'b', 'c']]],
        ]);
        $this->assertSame('order_sentences', $ok['type']);
    }

    public function test_short_answer_regex_ok(): void
    {
        $ok = (new QuestionTypeContract)->validateTask([
            'type' => 'short_answer', 'items' => [['id' => 'q', 'prompt' => '?']], 'scoring' => ['mode' => 'regex', 'answer_key' => ['q' => ['/^(blue|azure)$/i']]],
        ]);
        $this->assertSame('regex', $ok['scoring']['mode']);
    }

    public function test_writing_prompt_requires_rubric(): void
    {
        $this->expectException(ValidationException::class);
        (new QuestionTypeContract)->validateTask([
            'type' => 'writing_prompt', 'items' => [['id' => 'q', 'prompt' => 'Write...']], 'scoring' => ['mode' => 'exact', 'answer_key' => []],
        ]);
    }
}
