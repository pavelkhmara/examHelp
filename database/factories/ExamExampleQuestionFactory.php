<?php

namespace Database\Factories;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\ExamExampleQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExamExampleQuestionFactory extends Factory
{
    protected $model = ExamExampleQuestion::class;

    public function definition(): array
    {
        return [
            'exam_id' => Exam::factory(),
            'exam_category_id' => ExamCategory::factory(),
            'question' => 'Describe your daily routine in English.',
            'good_answer' => 'I wake up at 7, have breakfast, and commute by bus. After work I cook dinner and read.',
            'average_answer' => 'I wake up and go to work.',
            'bad_answer' => 'Work.',
            'rubric_breakdown' => null,
            'type' => 'writing_prompt',
            'payload' => [
                'max_score' => 10,
                'rubric' => [
                    ['criterion' => 'Content',    'weight' => 0.4, 'levels' => ['poor', 'fair', 'good', 'excellent']],
                    ['criterion' => 'Grammar',    'weight' => 0.3, 'levels' => ['poor', 'fair', 'good', 'excellent']],
                    ['criterion' => 'Vocabulary', 'weight' => 0.3, 'levels' => ['poor', 'fair', 'good', 'excellent']],
                ],
            ],
        ];
    }
}
