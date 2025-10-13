<?php

namespace Database\Factories;

use App\Models\Attempt;
use App\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AttemptAnswerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'attempt_id' => Attempt::factory(),
            'question_id' => Question::factory(),
            'selected_option_id' => null,
            'text_answer' => null,
            'is_correct' => null,
        ];
    }
}
