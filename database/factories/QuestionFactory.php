<?php

namespace Database\Factories;

use App\Models\Exam;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class QuestionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'exam_id' => Exam::factory(),
            'type' => $this->faker->randomElement(['MCQ','TEXT']),
            'prompt' => $this->faker->sentence(8),
            'position' => $this->faker->numberBetween(1, 20),
        ];
    }
}
