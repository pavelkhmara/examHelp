<?php

namespace Tests\Unit;

use App\Models\ExamExampleQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelTypeWhitelistTest extends TestCase
{
    use RefreshDatabase;

    public function test_exam_example_question_rejects_invalid_type(): void
    {
        $this->expectException(\ValueError::class);

        ExamExampleQuestion::create([
            'exam_id' => 1,
            'exam_category_id' => 1,
            'question' => 'dummy',
            'type' => 'not_a_valid_type',
            'payload' => null,
        ]);
    }

    public function test_exam_example_question_accepts_valid_type(): void
    {
        $row = ExamExampleQuestion::create([
            'exam_id' => 1,
            'exam_category_id' => 1,
            'question' => 'dummy',
            'type' => 'single_select',
            'payload' => ['sample' => true],
        ]);
        $this->assertSame('single_select', $row->type->value);
    }
}
