<?php

namespace Database\Factories;

use App\Models\Exam;
use App\Models\ExamDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ExamDocumentFactory extends Factory
{
    protected $model = ExamDocument::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),              // char(36)
            'exam_id' => Exam::factory(),              // FK -> exams.id (uuid)
            'generation_task_id' => null,
            'original_name' => $this->faker->words(3, true).'.pdf',
            'disk' => 'local',
            'path' => 'documents/'.$this->faker->uuid.'.pdf',
            'mime' => 'application/pdf',
            'size' => $this->faker->numberBetween(2_000, 2_000_000),
            'status' => 'uploaded',                    // enum: uploaded|extracting|completed|failed
            'extracted_text' => null,
            'error' => null,
            'meta' => [],
        ];
    }

    public function extracting(): self
    {
        return $this->state(fn () => ['status' => 'extracting']);
    }

    public function completed(string $text = '...extracted...'): self
    {
        return $this->state(fn () => ['status' => 'completed', 'extracted_text' => $text]);
    }

    public function failed(string $error = 'Extraction error'): self
    {
        return $this->state(fn () => ['status' => 'failed', 'error' => $error]);
    }
}
