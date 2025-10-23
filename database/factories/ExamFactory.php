<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ExamFactory extends Factory
{
    public function definition(): array
    {
        $title = $this->faker->unique()->words(3, true); // "Polish B1 Practice"

        return [
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'slug' => \Illuminate\Support\Str::slug($title),
            'title' => ucfirst($title),
            'description' => $this->faker->optional()->paragraph(),
            'level' => $this->faker->randomElement(['A1', 'A2', 'B1', 'B2', 'C1', 'C2']),
            'is_active' => $this->faker->boolean(85),
            'sources' => [],
            'meta' => [],
            'research_status' => 'queued',
            'categories_count' => 0,
            'examples_count' => 0,
        ];
    }
}
