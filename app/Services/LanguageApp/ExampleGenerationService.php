<?php

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\ExamExampleQuestion;
use App\Models\GenerationTask;
use App\Services\LanguageApp\Prompts\PromptExampleGeneration;
use App\Services\LanguageApp\Validators\JsonSchemaExamExamples;
use Illuminate\Support\Facades\Log;

/**
 * Service for generating example questions
 *
 * Responsibilities:
 * - Generate example questions for exam archetypes
 * - Validate and persist examples to database
 */
class ExampleGenerationService extends AbstractAiService
{
    /**
     * Generate example questions for exam archetypes
     */
    public function generateExamples(Exam $exam, GenerationTask $task, int $examplesPerArchetype = 1): array
    {
        // Get exam structure and archetypes from exam or task result
        $structure = $exam->exam_structure ?? $task->result['overview'] ?? [];
        $archetypes = $structure['global_archetypes'] ?? [];

        if (empty($archetypes)) {
            throw new \RuntimeException('No archetypes found in exam structure. Run research pipeline first.');
        }

        // Build exam info for prompt
        $examInfo = [
            'canonical' => $task->result['identity']['canonical'] ?? [],
            'title' => $exam->title,
            'level' => $exam->level,
        ];

        // Build prompt
        $prompt = PromptExampleGeneration::build(
            $examInfo,
            $archetypes,
            $examplesPerArchetype
        );

        // Build messages for AI
        $messages = [
            [
                'role' => 'system',
                'content' => $prompt,
            ],
        ];

        // Call AI directly (not through callAi which is for overview only)
        $payload = [
            'stage' => 'example_generation',
            'exam_slug' => $exam->slug,
            'exam_level' => $exam->level,
            'messages' => $messages,
        ];

        $res = $this->ai->generate($payload, [
            'schema' => $this->buildExampleSchema(),
        ]);

        $this->log($task, 'example_generation', $payload, $res);

        // Validate response
        $validator = new JsonSchemaExamExamples;
        $validated = $validator->validate($res['content'] ?? []);

        $examples = $validated['examples'] ?? [];

        // Persist examples to database
        $createdCount = $this->persistExamples($exam, $archetypes, $examples);

        // Update exam examples_count
        $exam->examples_count = $exam->examples()->count();
        $exam->save();

        return [
            'examples_created' => $createdCount,
            'total_archetypes' => count($archetypes),
            'examples_per_archetype' => $examplesPerArchetype,
        ];
    }

    /**
     * Persist examples to database
     */
    protected function persistExamples(Exam $exam, array $archetypes, array $examples): int
    {
        $createdCount = 0;

        foreach ($examples as $example) {
            // Find category for this archetype
            $archetypeId = $example['archetype_id'];
            $archetype = collect($archetypes)->firstWhere('id', $archetypeId);

            if (! $archetype) {
                Log::warning('Archetype not found for example', ['archetype_id' => $archetypeId]);

                continue;
            }

            // Get category name from archetype
            $categoryName = $archetype['category'] ?? 'unknown';

            // Find or create category
            $category = ExamCategory::firstOrCreate(
                [
                    'exam_id' => $exam->id,
                    'name' => $categoryName,
                ],
                [
                    'order' => 0,
                    'meta' => [],
                ]
            );

            // Create example question
            ExamExampleQuestion::create([
                'exam_id' => $exam->id,
                'exam_category_id' => $category->id,
                'question' => $example['question'],
                'description' => $example['description'],
                'duration_minutes' => $example['duration_minutes'],
                'instructions' => $example['instructions'],
                'example_response' => $example['example_response'],
                'assessment_guide' => $example['assessment_guide'],
                'type' => $example['type'],
                'payload' => $example['payload'],
            ]);

            $createdCount++;
        }

        return $createdCount;
    }

    /**
     * Build JSON schema for example generation
     */
    protected function buildExampleSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'examples' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'archetype_id' => ['type' => 'string'],
                            'question' => ['type' => 'string'],
                            'description' => ['type' => ['string', 'null']],
                            'duration_minutes' => ['type' => ['integer', 'null']],
                            'instructions' => ['type' => ['string', 'null']],
                            'example_response' => ['type' => ['object', 'null']],
                            'assessment_guide' => ['type' => ['string', 'null']],
                            'type' => ['type' => 'string'],
                            'payload' => ['type' => ['object', 'null']],
                        ],
                        'required' => ['archetype_id', 'question', 'type'],
                    ],
                ],
            ],
            'required' => ['examples'],
        ];
    }
}
