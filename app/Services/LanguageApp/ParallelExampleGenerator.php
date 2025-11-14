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
 * Parallel Example Generator
 *
 * Generates example questions for multiple archetypes in parallel using AsyncAiProvider
 * This is ~2-3x faster than sequential generation
 */
class ParallelExampleGenerator extends AbstractAiService
{
    public function __construct(
        AiProvider $ai,
        protected readonly JsonSchemaExamExamples $validator
    ) {
        parent::__construct($ai);
    }

    /**
     * Generate examples for all task archetypes in parallel
     *
     * @param  Exam  $exam
     * @param  GenerationTask|null  $task
     * @param  int  $examplesPerArchetype
     * @return array Results summary
     */
    public function generateBatch(Exam $exam, ?GenerationTask $task = null, int $examplesPerArchetype = 1): array
    {
        Log::info('[ParallelExampleGenerator] Starting batch generation', [
            'exam_id' => $exam->id,
            'task_id' => $task?->id,
            'examples_per_archetype' => $examplesPerArchetype,
        ]);

        // Get task_archetypes from structure_v2
        $structure = $exam->meta['structure_v2'] ?? null;

        if (!$structure) {
            throw new \RuntimeException('No structure_v2 found in exam meta. Run research pipeline first.');
        }

        $sections = $structure['sections'] ?? [];

        if (empty($sections)) {
            throw new \RuntimeException('No sections found in structure_v2.');
        }

        // Collect all task_archetypes with their section context
        $archetypesWithContext = [];
        foreach ($sections as $section) {
            $taskArchetypes = $section['task_archetypes'] ?? [];
            $sectionId = $section['id'];
            $sectionSkill = $section['skill'];

            foreach ($taskArchetypes as $archetype) {
                $archetypesWithContext[] = [
                    'archetype' => $archetype,
                    'section_id' => $sectionId,
                    'section_skill' => $sectionSkill,
                ];
            }
        }

        if (empty($archetypesWithContext)) {
            Log::warning('[ParallelExampleGenerator] No task_archetypes found in any section', [
                'exam_id' => $exam->id,
            ]);

            return [
                'success' => true,
                'examples_created' => 0,
                'total_archetypes' => 0,
                'message' => 'No task archetypes found to generate examples',
            ];
        }

        $totalArchetypes = count($archetypesWithContext);

        Log::info('[ParallelExampleGenerator] Found task archetypes', [
            'total_archetypes' => $totalArchetypes,
        ]);

        // Check if async is enabled
        $asyncEnabled = config('ai.async_enabled', false);

        if (!$asyncEnabled) {
            Log::warning('[ParallelExampleGenerator] Async disabled, falling back to sequential');

            return $this->generateSequential($exam, $task, $archetypesWithContext, $examplesPerArchetype);
        }

        // Generate examples in parallel
        $results = $this->generateParallel($exam, $task, $archetypesWithContext, $examplesPerArchetype);

        $totalCreated = array_sum(array_column($results, 'examples_created'));

        // Update exam examples_count
        $exam->examples_count = $exam->examples()->count();
        $exam->save();

        Log::info('[ParallelExampleGenerator] Batch generation completed', [
            'exam_id' => $exam->id,
            'total_archetypes' => $totalArchetypes,
            'total_examples_created' => $totalCreated,
        ]);

        return [
            'success' => true,
            'examples_created' => $totalCreated,
            'total_archetypes' => $totalArchetypes,
            'results' => $results,
        ];
    }

    /**
     * Generate examples in parallel using AsyncAiProvider
     */
    protected function generateParallel(
        Exam $exam,
        ?GenerationTask $task,
        array $archetypesWithContext,
        int $examplesPerArchetype
    ): array {
        Log::info('[ParallelExampleGenerator] Starting parallel generation', [
            'archetypes_count' => count($archetypesWithContext),
        ]);

        // Build exam info for prompts
        $examInfo = [
            'canonical' => $task?->result['identity']['canonical'] ?? [],
            'title' => $exam->title,
            'level' => $exam->level,
        ];

        // Prepare AI requests for each archetype
        $requests = [];
        $archetypeMap = []; // Map request key to archetype context

        foreach ($archetypesWithContext as $idx => $archetypeCtx) {
            $archetype = $archetypeCtx['archetype'];
            $key = "archetype_{$idx}";
            $archetypeMap[$key] = $archetypeCtx;

            // Build prompt for this archetype
            $prompt = PromptExampleGeneration::build(
                $examInfo,
                [$archetype], // Single archetype
                $examplesPerArchetype
            );

            $messages = [
                [
                    'role' => 'system',
                    'content' => $prompt,
                ],
            ];

            // Prepare request
            $requests[] = [
                'key' => $key,
                'payload' => [
                    'stage' => 'example_generation',
                    'exam_slug' => $exam->slug,
                    'exam_level' => $exam->level,
                    'messages' => $messages,
                ],
                'opts' => [
                    'schema' => $this->buildExampleSchema(),
                ],
            ];
        }

        try {
            // Create async AI provider
            $asyncProvider = AiProviderFactory::makeAsync('openai', config('ai'));

            // Send all requests in parallel
            Log::info('[ParallelExampleGenerator] Sending parallel AI requests', [
                'requests_count' => count($requests),
            ]);

            $startTime = microtime(true);
            $aiResults = $asyncProvider->generateBatch($requests)->wait();
            $duration = microtime(true) - $startTime;

            Log::info('[ParallelExampleGenerator] Parallel AI requests completed', [
                'duration_seconds' => round($duration, 2),
                'requests_count' => count($requests),
            ]);

            // Process results for each archetype
            $results = [];
            foreach ($aiResults as $key => $aiResult) {
                $archetypeCtx = $archetypeMap[$key];
                $archetype = $archetypeCtx['archetype'];
                $archetypeId = $archetype['id'] ?? "archetype_{$key}";

                // Log AI request/response
                if ($task) {
                    $this->log(
                        task: $task,
                        stage: "example_generation_{$archetypeId}",
                        request: $aiResult['sent_messages'] ?? [],
                        response: $aiResult
                    );
                }

                try {
                    // Parse and validate AI response
                    $content = $aiResult['content'] ?? [];
                    $validated = $this->validator->validate($content);
                    $examples = $validated['examples'] ?? [];

                    // Persist examples to database
                    $createdCount = $this->persistExamples(
                        $exam,
                        $archetypeCtx,
                        $examples
                    );

                    // Log validation result
                    if ($task) {
                        $this->log(
                            task: $task,
                            stage: "example_generation_{$archetypeId}_validated",
                            request: ['examples_raw' => count($examples)],
                            response: [
                                'created' => $createdCount,
                            ]
                        );
                    }

                    $results[] = [
                        'archetype_id' => $archetypeId,
                        'success' => true,
                        'examples_created' => $createdCount,
                    ];

                    Log::info('[ParallelExampleGenerator] Archetype completed', [
                        'archetype_id' => $archetypeId,
                        'examples_created' => $createdCount,
                    ]);
                } catch (\Throwable $e) {
                    // Log error
                    if ($task) {
                        $this->log(
                            task: $task,
                            stage: "example_generation_{$archetypeId}_error",
                            request: ['examples_attempted' => count($examples ?? [])],
                            response: ['error' => $e->getMessage()]
                        );
                    }

                    $results[] = [
                        'archetype_id' => $archetypeId,
                        'success' => false,
                        'error' => $e->getMessage(),
                    ];

                    Log::error('[ParallelExampleGenerator] Archetype processing failed', [
                        'archetype_id' => $archetypeId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $results;
        } catch (\Throwable $e) {
            Log::error('[ParallelExampleGenerator] Parallel generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Fallback: Sequential generation (when async is disabled)
     */
    protected function generateSequential(
        Exam $exam,
        ?GenerationTask $task,
        array $archetypesWithContext,
        int $examplesPerArchetype
    ): array {
        $results = [];
        $totalCreated = 0;

        // Build exam info
        $examInfo = [
            'canonical' => $task?->result['identity']['canonical'] ?? [],
            'title' => $exam->title,
            'level' => $exam->level,
        ];

        foreach ($archetypesWithContext as $idx => $archetypeCtx) {
            $archetype = $archetypeCtx['archetype'];
            $archetypeId = $archetype['id'] ?? "archetype_{$idx}";

            try {
                // Build prompt
                $prompt = PromptExampleGeneration::build(
                    $examInfo,
                    [$archetype],
                    $examplesPerArchetype
                );

                $messages = [
                    [
                        'role' => 'system',
                        'content' => $prompt,
                    ],
                ];

                // Call AI (synchronously)
                $payload = [
                    'stage' => 'example_generation',
                    'exam_slug' => $exam->slug,
                    'exam_level' => $exam->level,
                    'messages' => $messages,
                ];

                $aiResult = $this->ai->generate($payload, [
                    'schema' => $this->buildExampleSchema(),
                ]);

                // Log AI request/response
                if ($task) {
                    $this->log(
                        task: $task,
                        stage: "example_generation_{$archetypeId}",
                        request: $payload,
                        response: $aiResult
                    );
                }

                // Validate response
                $validated = $this->validator->validate($aiResult['content'] ?? []);
                $examples = $validated['examples'] ?? [];

                // Persist examples
                $createdCount = $this->persistExamples(
                    $exam,
                    $archetypeCtx,
                    $examples
                );

                $results[] = [
                    'archetype_id' => $archetypeId,
                    'success' => true,
                    'examples_created' => $createdCount,
                ];

                $totalCreated += $createdCount;

                Log::info('[ParallelExampleGenerator] Archetype completed (sequential)', [
                    'archetype_id' => $archetypeId,
                    'examples_created' => $createdCount,
                ]);
            } catch (\Throwable $e) {
                $results[] = [
                    'archetype_id' => $archetypeId,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];

                Log::error('[ParallelExampleGenerator] Archetype failed (sequential)', [
                    'archetype_id' => $archetypeId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Update exam examples_count
        $exam->examples_count = $exam->examples()->count();
        $exam->save();

        return [
            'success' => true,
            'examples_created' => $totalCreated,
            'total_archetypes' => count($archetypesWithContext),
            'results' => $results,
        ];
    }

    /**
     * Persist examples to database
     */
    protected function persistExamples(Exam $exam, array $archetypeCtx, array $examples): int
    {
        $createdCount = 0;
        $archetype = $archetypeCtx['archetype'];
        $sectionId = $archetypeCtx['section_id'];
        $sectionSkill = $archetypeCtx['section_skill'];

        // Find or create category for this section
        $category = ExamCategory::firstOrCreate(
            [
                'exam_id' => $exam->id,
                'key' => $sectionId,
            ],
            [
                'name' => $sectionId,
                'skill' => $sectionSkill,
                'order' => 0,
                'meta' => [],
            ]
        );

        foreach ($examples as $example) {
            // Verify archetype_id matches
            $exampleArchetypeId = $example['archetype_id'] ?? null;
            $expectedArchetypeId = $archetype['id'] ?? null;

            if ($exampleArchetypeId !== $expectedArchetypeId) {
                Log::warning('[ParallelExampleGenerator] Archetype ID mismatch', [
                    'expected' => $expectedArchetypeId,
                    'got' => $exampleArchetypeId,
                ]);

                continue;
            }

            // Create example question
            ExamExampleQuestion::create([
                'exam_id' => $exam->id,
                'exam_category_id' => $category->id,
                'question' => $example['question'],
                'description' => $example['description'] ?? null,
                'duration_minutes' => $example['duration_minutes'] ?? null,
                'instructions' => $example['instructions'] ?? null,
                'example_response' => $example['example_response'] ?? null,
                'assessment_guide' => $example['assessment_guide'] ?? null,
                'type' => $example['type'],
                'payload' => $example['payload'] ?? null,
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
