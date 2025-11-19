<?php

namespace Database\Factories;

use App\Models\Exam;
use App\Models\ExamCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Question Factory - V2 Format
 *
 * Creates questions following the s2_exam_question_archetype_v2.json schema
 */
class QuestionFactory extends Factory
{
    public function definition(): array
    {
        $type = $this->faker->randomElement(['single_select', 'multi_select', 'short_answer', 'writing_prompt']);

        // Create exam and category
        $exam = Exam::factory()->create();
        $category = ExamCategory::factory()->create(['exam_id' => $exam->id]);

        return [
            // id auto-increment, don't set manually
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'question_id' => 'q-' . Str::random(10),  // unique question identifier
            'type' => $type,
            'skills_measured' => ['reading'],
            'time_limit_sec' => 300,
            'instructions' => [
                'brief' => $this->faker->sentence(),
                'full' => $this->faker->paragraph(),
            ],
            'stimulus' => [
                'text_html' => '<p>' . $this->faker->paragraph() . '</p>',
                'images' => [],
                'audio' => [],
                'media_metadata' => [
                    'images' => [],
                    'audio' => [],
                    'video' => [],
                ],
            ],
            'interaction' => [
                'response_type' => $type === 'single_select' || $type === 'multi_select' ? 'selection' : 'text',
                'options' => $type === 'single_select' || $type === 'multi_select' ? [
                    ['id' => 'A', 'label' => 'Option A'],
                    ['id' => 'B', 'label' => 'Option B'],
                    ['id' => 'C', 'label' => 'Option C'],
                ] : [],
            ],
            'response' => [
                'mode' => $type === 'single_select' || $type === 'multi_select' ? 'selection' : 'text',
                'attempts' => 1,
            ],
            'scoring' => [
                'method' => 'keyed',
                'max_score' => 1,
                'answer_key' => $type === 'single_select' ? 'B' : ($type === 'multi_select' ? ['B'] : null),
            ],
            'metadata' => [
                'cefr' => ['B2'],
                'difficulty' => 'medium',
                'topic' => 'general',
                'language' => 'en',
            ],
            'status' => 'draft',
        ];
    }
}
