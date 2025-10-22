<?php

namespace Database\Factories;

use App\Models\Exam;
use App\Models\ExamCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ExamCategoryFactory extends Factory
{
    protected $model = ExamCategory::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true); // "Writing Tasks"
        return [
            'exam_id'     => Exam::factory(),
            'key'         => Str::slug($name),
            'name'        => ucfirst($name),
            'description' => $this->faker->optional()->sentence(10),
            'meta'        => [],
            'order'       => $this->faker->numberBetween(1, 99),
        ];
    }
}
