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
        $exam = \App\Models\Exam::factory()->create();
        $category = \App\Models\ExamCategory::factory()->create(['exam_id' => $exam->id]);

        $row = ExamExampleQuestion::create([
            'exam_id' => $exam->id,
            'exam_category_id' => $category->id,
            'question' => 'dummy',
            'type' => 'single_select',
            'payload' => [
                'options' => [
                    ['id' => 'A', 'text' => 'Option A'],
                    ['id' => 'B', 'text' => 'Option B'],
                ],
                'answer' => 'A',
            ],
        ]);
        $this->assertSame('single_select', $row->type->value);
    }
}
