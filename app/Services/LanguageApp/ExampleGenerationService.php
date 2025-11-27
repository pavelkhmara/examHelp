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
 * - Supports both V1 (question_archetypes) and V2 (question_archetypes from structure_v2)
 */
class ExampleGenerationService extends AbstractAiService
{
    public function __construct(
        AiProvider $ai,
        protected readonly ParallelExampleGenerator $parallelGenerator,
        protected readonly QuestionImageProcessor $imageProcessor
    ) {
        parent::__construct($ai);
    }

    /**
     * Generate example questions - auto-detects V1 vs V2 architecture
     */
    public function generateExamples(Exam $exam, GenerationTask $task, int $examplesPerArchetype = 1): array
    {
        // Check if V2 architecture (structure_v2 exists with question_archetypes)
        $structureV2 = $exam->meta['structure_v2'] ?? null;
        $isV2 = ! empty($structureV2);

        if ($isV2) {
            // V2 architecture: use ParallelExampleGenerator with question_archetypes
            Log::info('Using V2 architecture for example generation', [
                'exam_id' => $exam->id,
            ]);

            return $this->parallelGenerator->generateBatch($exam, $task, $examplesPerArchetype);
        }

        // V1 architecture: use legacy sequential generation with question_archetypes
        Log::info('Using V1 architecture for example generation', [
            'exam_id' => $exam->id,
        ]);

        return $this->generateExamplesV1($exam, $task, $examplesPerArchetype);
    }

    /**
     * Generate example questions for exam question_archetypes (V1 architecture)
     */
    protected function generateExamplesV1(Exam $exam, GenerationTask $task, int $examplesPerArchetype = 1): array
    {
        // Get exam structure and question_archetypes from exam or task result
        $structure = $exam->exam_structure ?? $task->result['overview'] ?? [];
        $questionArchetypes = $structure['question_archetypes'] ?? [];

        if (empty($questionArchetypes)) {
            throw new \RuntimeException('No question_archetypes found in exam structure. Run research pipeline first.');
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
            $questionArchetypes,
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
        $createdQuestions = $this->persistExamples($exam, $questionArchetypes, $examples);

        // Process images for questions (if enabled)
        $imageStats = ['processed' => 0, 'images_generated' => 0, 'errors' => 0];
        if (config('ai.images.enabled', false) && ! empty($createdQuestions)) {
            try {
                $imageStats = $this->imageProcessor->processQuestions($createdQuestions);
                Log::info('[ExampleGenerationService] Image processing completed', $imageStats);
            } catch (\Exception $e) {
                Log::error('[ExampleGenerationService] Image processing failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Update exam examples_count
        $exam->examples_count = $exam->examples()->count();
        $exam->save();

        return [
            'examples_created' => count($createdQuestions),
            'total_archetypes' => count($questionArchetypes),
            'examples_per_archetype' => $examplesPerArchetype,
            'images_generated' => $imageStats['images_generated'],
        ];
    }

    /**
     * Persist examples to database (V1 architecture)
     *
     * @return array<ExamExampleQuestion>
     */
    protected function persistExamples(Exam $exam, array $questionArchetypes, array $examples): array
    {
        $createdQuestions = [];

        foreach ($examples as $example) {
            // Find category for this question_archetype
            $archetypeId = $example['archetype_id'];
            $questionArchetype = collect($questionArchetypes)->firstWhere('id', $archetypeId);

            if (! $questionArchetype) {
                Log::warning('Question archetype not found for example', ['archetype_id' => $archetypeId]);

                continue;
            }

            // Get category name from question_archetype
            $categoryName = $questionArchetype['category'] ?? 'unknown';

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
            $question = ExamExampleQuestion::create([
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

            $createdQuestions[] = $question;
        }

        return $createdQuestions;
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
