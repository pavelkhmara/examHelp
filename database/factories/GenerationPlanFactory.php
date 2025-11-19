<?php

namespace Database\Factories;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\GenerationPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GenerationPlan>
 */
class GenerationPlanFactory extends Factory
{
    protected $model = GenerationPlan::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exam_id' => Exam::factory(),
            'section_id' => ExamCategory::factory(),
            'assembly_mode' => 'inline',
            'plan_data' => [
                'placeholders' => [
                    ['id' => 'placeholder-1', 'type' => 'single_select'],
                ],
            ],
            'status' => 'pending',
            'total_questions' => 1,
            'generated_questions' => 0,
            'error' => null,
            'started_at' => null,
            'completed_at' => null,
            'attached_at' => null,
        ];
    }

    /**
     * Create a plan for pool mode
     */
    public function pool(): static
    {
        return $this->state(fn (array $attributes) => [
            'assembly_mode' => 'pool',
            'plan_data' => [
                'pool_id' => 'test-pool',
                'filters' => ['difficulty' => 'medium'],
                'pick' => 10,
                'seed' => 'test-seed',
            ],
            'total_questions' => 10,
        ]);
    }

    /**
     * Create a plan for blueprint mode
     */
    public function blueprint(): static
    {
        return $this->state(fn (array $attributes) => [
            'assembly_mode' => 'blueprint',
            'plan_data' => [
                'slots' => [
                    [
                        'slot' => 'slot-1',
                        'from_pool' => 'test-pool',
                        'pick' => 5,
                        'filters' => ['difficulty' => 'easy'],
                    ],
                    [
                        'slot' => 'slot-2',
                        'from_pool' => 'test-pool',
                        'pick' => 5,
                        'filters' => ['difficulty' => 'hard'],
                    ],
                ],
            ],
            'total_questions' => 10,
        ]);
    }

    /**
     * Create a plan for inline mode with question_groups
     */
    public function withQuestionGroups(int $groupsCount = 1, int $questionsPerGroup = 2): static
    {
        $groups = [];
        $totalQuestions = 0;

        for ($i = 1; $i <= $groupsCount; $i++) {
            $questions = [];
            for ($j = 1; $j <= $questionsPerGroup; $j++) {
                $questions[] = [
                    'id' => "group-{$i}-q{$j}",
                    'type' => 'single_select',
                ];
                $totalQuestions++;
            }

            $groups[] = [
                'id' => "group-{$i}",
                'title' => "Task {$i}",
                'stimulus' => [
                    'audio' => ["https://example.com/audio-{$i}.mp3"],
                ],
                'playback_settings' => [
                    'max_plays' => 1,
                    'enforcement' => 'strict',
                ],
                'questions' => $questions,
            ];
        }

        return $this->state(fn (array $attributes) => [
            'assembly_mode' => 'inline',
            'plan_data' => [
                'question_groups' => $groups,
            ],
            'total_questions' => $totalQuestions,
        ]);
    }

    /**
     * Create a plan for inline mode with placeholders (legacy)
     */
    public function withPlaceholders(int $count = 2): static
    {
        $placeholders = [];
        for ($i = 1; $i <= $count; $i++) {
            $placeholders[] = [
                'id' => "placeholder-{$i}",
                'type' => 'essay',
            ];
        }

        return $this->state(fn (array $attributes) => [
            'assembly_mode' => 'inline',
            'plan_data' => [
                'placeholders' => $placeholders,
            ],
            'total_questions' => $count,
        ]);
    }

    /**
     * Mark plan as in progress
     */
    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
    }

    /**
     * Mark plan as completed
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
            'generated_questions' => $attributes['total_questions'] ?? 1,
        ]);
    }

    /**
     * Mark plan as failed
     */
    public function failed(string $error = 'Generation failed'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'started_at' => now()->subMinutes(5),
            'error' => $error,
        ]);
    }
}
