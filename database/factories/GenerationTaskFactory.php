<?php

namespace Database\Factories;

use App\Models\Exam;
use Illuminate\Database\Eloquent\Factories\Factory;

class GenerationTaskFactory extends Factory
{
    public function definition(): array
    {
        // Create Exam without events to avoid triggering ExamObserver
        $exam = Exam::withoutEvents(fn () => Exam::factory()->create());

        return [
            'exam_id' => $exam->id,
            'type' => $this->faker->randomElement(['research', 'generate_questions', 'evaluate', 'identity_check']),
            'status' => 'queued',
            'request' => [],
            'response' => null,
            'result' => null,
            'error' => null,
            'attempts' => 0,
            'idempotency_key' => null,
            'activities' => [],
            'heartbeat_at' => null,
        ];
    }

    /**
     * Задача в состоянии running
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'running',
            'heartbeat_at' => now(),
        ]);
    }

    /**
     * Задача completed
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'result' => ['completed' => true],
        ]);
    }

    /**
     * Задача failed
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error' => $this->faker->sentence(),
        ]);
    }

    /**
     * Задача pending_confirmation
     */
    public function pendingConfirmation(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending_confirmation',
        ]);
    }

    /**
     * Задача pending_clarification
     */
    public function pendingClarification(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending_clarification',
        ]);
    }
}
