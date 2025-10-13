<?php

namespace Database\Factories;

use App\Models\Exam;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'exam_id' => Exam::factory(),
            'user_id' => null,
            'started_at' => now(),
            'completed_at' => null,
            'score' => null,
        ];
    }
}
