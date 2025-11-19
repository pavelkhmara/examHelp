<?php

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\GenerationPlan;
use App\Services\LanguageApp\Prompts\PromptQuestionSynthesis;
use App\Services\LanguageApp\Schemas\QuestionArraySchema;
use App\Services\LanguageApp\Validators\JsonSchemaQuestionV2;
use Illuminate\Support\Facades\Log;

/**
 * Parallel Question Synthesizer - V2
 *
 * Generates questions for multiple sections in parallel using AsyncAiProvider
 * This is ~2-3x faster than sequential generation
 */
class ParallelQuestionSynthesizer extends AbstractAiService
{
    private const COMPLEX_TYPES = [
        'essay', 'writing_prompt', 'speaking_prompt',
        'matching', 'diagram_labeling', 'graph_description',
        'translation', 'roleplay',
    ];

    public function __construct(
        AiProvider $ai,
        protected readonly JsonSchemaQuestionV2 $validator,
        protected readonly QuestionValidator $questionValidator,
        protected readonly QuestionDeduplicator $deduplicator,
        protected readonly QuestionAttacher $attacher
    ) {
        parent::__construct($ai);
    }

    /**
     * Synthesize questions for multiple sections in parallel
     *
     * @param  \Illuminate\Support\Collection  $plans
     * @return array Results per section
     */
    public function synthesizeBatch($plans, Exam $exam, ?\App\Models\GenerationTask $task = null): array
    {
        Log::info('[ParallelQuestionSynthesizer] Starting batch synthesis', [
            'exam_id' => $exam->id,
            'plans_count' => $plans->count(),
            'task_id' => $task?->id,
        ]);

        // Check if async is enabled
        $asyncEnabled = config('ai.async_enabled', false);

        if (! $asyncEnabled) {
            Log::warning('[ParallelQuestionSynthesizer] Async disabled, falling back to sequential');

            return $this->synthesizeSequential($plans, $exam);
        }

        // Separate plans by assembly mode
        $poolPlans = $plans->where('assembly_mode', 'pool');
        $blueprintPlans = $plans->where('assembly_mode', 'blueprint');
        $inlinePlans = $plans->where('assembly_mode', 'inline');

        $results = [];

        // Pool mode: no AI needed (select from existing pool)
        // NOTE: Currently pools don't exist yet, so still generates via AI
        foreach ($poolPlans as $plan) {
            $results[] = $this->synthesizeSinglePlan($plan, $exam);
        }

        // Blueprint & Inline: parallel AI requests per section
        $aiPlans = $blueprintPlans->concat($inlinePlans);

        if ($aiPlans->isNotEmpty()) {
            $parallelResults = $this->synthesizeParallel($aiPlans, $exam, $task);
            $results = array_merge($results, $parallelResults);
        }

        Log::info('[ParallelQuestionSynthesizer] Batch synthesis completed', [
            'sections_processed' => count($results),
        ]);

        return $results;
    }

    /**
     * Synthesize multiple sections in parallel using AsyncAiProvider
     */
    protected function synthesizeParallel($plans, Exam $exam, ?\App\Models\GenerationTask $task = null): array
    {
        Log::info('[ParallelQuestionSynthesizer] Starting parallel synthesis', [
            'sections_count' => $plans->count(),
            'task_id' => $task?->id,
        ]);

        // Prepare AI requests for all sections
        $requests = [];
        $planMap = []; // Map request key to plan

        foreach ($plans as $plan) {
            $key = "section_{$plan->id}";
            $planMap[$key] = $plan;

            // Get section metadata
            $section = $this->getSectionMetadata($exam, $plan->section_id);

            // Prepare request based on assembly mode
            $requestData = null;
            if ($plan->assembly_mode === 'blueprint') {
                $requestData = $this->prepareBlueprintRequest($plan, $exam, $section);
            } elseif ($plan->assembly_mode === 'inline') {
                $requestData = $this->prepareInlineRequest($plan, $exam, $section);
            }

            // Add key to request for AsyncProvider
            if ($requestData) {
                $requests[] = [
                    'key' => $key,
                    'payload' => $requestData['payload'] ?? [],
                    'opts' => $requestData['opts'] ?? [],
                ];
            }

            // Mark plan as in progress
            $plan->markAsInProgress();
        }

        try {
            // Create async AI provider
            $asyncProvider = AiProviderFactory::makeAsync('openai', config('ai'));

            // Send all requests in parallel
            Log::info('[ParallelQuestionSynthesizer] Sending parallel AI requests', [
                'requests_count' => count($requests),
            ]);

            $startTime = microtime(true);
            $aiResults = $asyncProvider->generateBatch($requests)->wait();
            $duration = microtime(true) - $startTime;

            Log::info('[ParallelQuestionSynthesizer] Parallel AI requests completed', [
                'duration_seconds' => round($duration, 2),
                'requests_count' => count($requests),
            ]);

            // Process results for each section
            $results = [];
            foreach ($aiResults as $key => $aiResult) {
                $plan = $planMap[$key];
                $category = \App\Models\ExamCategory::find($plan->section_id);
                $sectionName = $category ? $category->name : "section_{$plan->section_id}";

                // Log AI request/response
                if ($task) {
                    $this->log(
                        task: $task,
                        stage: "synthesis_{$sectionName}",
                        request: $aiResult['sent_messages'] ?? [],
                        response: $aiResult
                    );
                }

                try {
                    // Extract questions from response (Structured Outputs wraps in {"questions": [...]})
                    $questions = $this->parseAndValidateQuestions($aiResult);

                    // Validate, deduplicate, attach
                    $validatedQuestions = $this->questionValidator->validateAndFinalize($questions, $plan, $exam);
                    $dedupedQuestions = $this->deduplicator->detectDuplicates($validatedQuestions, $exam);
                    $attachedQuestions = $this->attacher->attachToExam($dedupedQuestions, $plan, $exam);

                    // Log validation result
                    if ($task) {
                        $this->log(
                            task: $task,
                            stage: "synthesis_{$sectionName}_validated",
                            request: ['questions_raw' => count($questions)],
                            response: [
                                'validated' => count($validatedQuestions),
                                'deduped' => count($dedupedQuestions),
                                'attached' => count($attachedQuestions),
                            ]
                        );
                    }

                    // Mark plan as completed
                    $plan->markAsCompleted();

                    $results[] = [
                        'plan_id' => $plan->id,
                        'section_id' => $plan->section_id,
                        'success' => true,
                        'generated' => count($questions),
                        'validated' => count($validatedQuestions),
                        'attached' => count($attachedQuestions),
                    ];

                    Log::info('[ParallelQuestionSynthesizer] Section completed', [
                        'plan_id' => $plan->id,
                        'questions_attached' => count($attachedQuestions),
                    ]);
                } catch (\Throwable $e) {
                    // Log error
                    if ($task) {
                        $this->log(
                            task: $task,
                            stage: "synthesis_{$sectionName}_error",
                            request: ['questions_attempted' => count($questions ?? [])],
                            response: ['error' => $e->getMessage()]
                        );
                    }

                    // Mark plan as failed
                    $plan->markAsFailed($e->getMessage());

                    $results[] = [
                        'plan_id' => $plan->id,
                        'section_id' => $plan->section_id,
                        'success' => false,
                        'error' => $e->getMessage(),
                    ];

                    Log::error('[ParallelQuestionSynthesizer] Section processing failed', [
                        'plan_id' => $plan->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $results;
        } catch (\Throwable $e) {
            Log::error('[ParallelQuestionSynthesizer] Parallel synthesis failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark all plans as failed
            foreach ($plans as $plan) {
                $plan->markAsFailed('Parallel synthesis error: '.$e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Fallback: Sequential synthesis (when async is disabled)
     */
    protected function synthesizeSequential($plans, Exam $exam): array
    {
        $results = [];

        foreach ($plans as $plan) {
            $results[] = $this->synthesizeSinglePlan($plan, $exam);
        }

        return $results;
    }

    /**
     * Synthesize single plan (fallback for sequential mode)
     */
    protected function synthesizeSinglePlan(GenerationPlan $plan, Exam $exam): array
    {
        try {
            $plan->markAsInProgress();

            // Use existing QuestionSynthesizer for single plan
            $synthesizer = app(QuestionSynthesizer::class);
            $questions = $synthesizer->synthesize($plan, $exam);

            // Validate, deduplicate, attach
            $validatedQuestions = $this->questionValidator->validateAndFinalize($questions, $plan, $exam);
            $dedupedQuestions = $this->deduplicator->detectDuplicates($validatedQuestions, $exam);
            $attachedQuestions = $this->attacher->attachToExam($dedupedQuestions, $plan, $exam);

            $plan->markAsCompleted();

            return [
                'plan_id' => $plan->id,
                'section_id' => $plan->section_id,
                'success' => true,
                'generated' => count($questions),
                'validated' => count($validatedQuestions),
                'attached' => count($attachedQuestions),
            ];
        } catch (\Throwable $e) {
            $plan->markAsFailed($e->getMessage());

            return [
                'plan_id' => $plan->id,
                'section_id' => $plan->section_id,
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Prepare AI request for blueprint mode
     */
    protected function prepareBlueprintRequest(GenerationPlan $plan, Exam $exam, array $section): array
    {
        $planData = $plan->plan_data;
        $slots = $planData['slots'] ?? [];
        $totalQuestions = $plan->total_questions;

        // For simplicity, generate all blueprint questions in one AI request
        // More advanced: generate each slot separately
        $firstSlot = $slots[0] ?? [];
        $archetype = $this->getArchetypeForPool($section, $firstSlot['filters'] ?? []);

        $questionType = $archetype['type'] ?? 'single_select';
        $model = $this->selectModelForType($questionType);

        $prompt = PromptQuestionSynthesis::build(
            questionType: $questionType,
            archetypeConfig: $archetype,
            sectionSkill: $section['skill'],
            examLanguage: \App\Support\LanguageHelper::getLanguageName($exam->language_of_test),
            examLevel: $exam->level ?? 'B2',
            quantity: $totalQuestions,
            filters: null,
            contextHint: "Generate questions for blueprint mode ({$totalQuestions} questions total)"
        );

        $examLanguage = \App\Support\LanguageHelper::getLanguageName($exam->language_of_test);
        $userPrompt = "Generate exactly {$totalQuestions} questions of type {$questionType} for a {$examLanguage} language exam at {$exam->level} level. ALL user-facing content (instructions, stimulus, options) MUST be in {$examLanguage}. Return ONLY a valid JSON array with {$totalQuestions} question objects.";

        return [
            'payload' => [
                'exam_title' => $exam->title,
                'input' => $userPrompt,
                'user_input' => $userPrompt,
            ],
            'opts' => [
                'prompt_class' => PromptQuestionSynthesis::class,
                'prompt_args' => [
                    $questionType,
                    $archetype,
                    $section['skill'],
                    \App\Support\LanguageHelper::getLanguageName($exam->language_of_test),
                    $exam->level ?? 'B2',
                    $totalQuestions,
                    null,
                    null,
                ],
                'model' => $model,
                'json_schema' => QuestionArraySchema::getSchema($questionType),
                'json_schema_name' => 'question_generation',
            ],
        ];
    }

    /**
     * Prepare AI request for inline mode
     */
    protected function prepareInlineRequest(GenerationPlan $plan, Exam $exam, array $section): array
    {
        $planData = $plan->plan_data;
        $placeholders = $planData['placeholders'] ?? [];
        $totalQuestions = count($placeholders);

        // For simplicity, generate all inline questions in one AI request
        $firstPlaceholder = $placeholders[0] ?? [];
        $questionType = $firstPlaceholder['type'] ?? 'single_select';
        $config = $firstPlaceholder['config'] ?? [];

        $model = $this->selectModelForType($questionType);

        $prompt = PromptQuestionSynthesis::build(
            questionType: $questionType,
            archetypeConfig: $config,
            sectionSkill: $section['skill'],
            examLanguage: \App\Support\LanguageHelper::getLanguageName($exam->language_of_test),
            examLevel: $exam->level ?? 'B2',
            quantity: $totalQuestions,
            filters: null,
            contextHint: "Generate questions for inline mode ({$totalQuestions} questions total)"
        );

        $examLanguage = \App\Support\LanguageHelper::getLanguageName($exam->language_of_test);
        $userPrompt = "Generate exactly {$totalQuestions} questions of type {$questionType} for a {$examLanguage} language exam at {$exam->level} level. ALL user-facing content (instructions, stimulus, options) MUST be in {$examLanguage}. Return ONLY a valid JSON array with {$totalQuestions} question objects.";

        return [
            'payload' => [
                'exam_title' => $exam->title,
                'input' => $userPrompt,
                'user_input' => $userPrompt,
            ],
            'opts' => [
                'prompt_class' => PromptQuestionSynthesis::class,
                'prompt_args' => [
                    $questionType,
                    $config,
                    $section['skill'],
                    \App\Support\LanguageHelper::getLanguageName($exam->language_of_test),
                    $exam->level ?? 'B2',
                    $totalQuestions,
                    null,
                    null,
                ],
                'model' => $model,
                'json_schema' => QuestionArraySchema::getSchema($questionType),
                'json_schema_name' => 'question_generation',
            ],
        ];
    }

    /**
     * Get section metadata from exam structure
     */
    protected function getSectionMetadata(Exam $exam, int $categoryId): array
    {
        $structure = $exam->meta['structure_v2'] ?? null;

        if (! $structure) {
            throw new \Exception('Exam structure_v2 not found');
        }

        $sections = $structure['sections'] ?? [];

        // Find section by category relationship
        $category = \App\Models\ExamCategory::find($categoryId);

        if (! $category) {
            throw new \Exception("ExamCategory not found: {$categoryId}");
        }

        foreach ($sections as $section) {
            if (($section['skill'] ?? null) === $category->skill) {
                return $section;
            }
        }

        throw new \Exception("Section not found for category {$categoryId} (skill: {$category->skill})");
    }

    /**
     * Get archetype for pool based on filters
     */
    protected function getArchetypeForPool(array $section, array $filters): array
    {
        $archetypes = $section['question_archetypes'] ?? [];

        if (empty($archetypes)) {
            throw new \Exception('No question archetypes found in section');
        }

        // For simplicity, return first archetype
        // More advanced: filter by $filters
        return $archetypes[0];
    }

    /**
     * Select AI model based on question type complexity
     */
    protected function selectModelForType(string $questionType): string
    {
        return in_array($questionType, self::COMPLEX_TYPES) ? 'thinking' : 'default';
    }

    /**
     * Parse and validate AI response
     *
     * @param  array  $aiResult  Full AI response from AsyncOpenAiProvider
     * @return array Validated questions array
     */
    protected function parseAndValidateQuestions(array $aiResult): array
    {
        // Step 1: Extract questions from response
        // With Structured Outputs: {"questions": [...]}
        // Legacy json_object: could be array or object
        $content = $aiResult['content'] ?? null;

        if (! $content || ! is_array($content)) {
            throw new \Exception('AI response content is missing or invalid');
        }

        // If Structured Outputs used, extract 'questions' array
        if (isset($content['questions']) && is_array($content['questions'])) {
            $questions = $content['questions'];
            Log::debug('[ParallelQuestionSynthesizer] Extracted questions from Structured Outputs', [
                'count' => count($questions),
            ]);
        }
        // Legacy: direct array (should not happen with json_schema)
        elseif (isset($content[0])) {
            $questions = $content;
            Log::debug('[ParallelQuestionSynthesizer] Using legacy array format', [
                'count' => count($questions),
            ]);
        }
        // Legacy: single object wrapped in array
        else {
            $questions = [$content];
            Log::debug('[ParallelQuestionSynthesizer] Wrapped single object in array');
        }

        // Step 2: Validate each question with JsonSchemaQuestionV2
        $validated = [];
        foreach ($questions as $index => $question) {
            try {
                $this->validator->validate($question);
                $validated[] = $question;
            } catch (\Throwable $e) {
                Log::warning('[ParallelQuestionSynthesizer] Question validation failed', [
                    'index' => $index,
                    'error' => $e->getMessage(),
                    'question_id' => $question['id'] ?? 'unknown',
                ]);
                // Skip invalid questions (should rarely happen with Structured Outputs)
            }
        }

        if (empty($validated)) {
            throw new \Exception('No valid questions after validation');
        }

        Log::info('[ParallelQuestionSynthesizer] Questions validated', [
            'total' => count($questions),
            'valid' => count($validated),
            'invalid' => count($questions) - count($validated),
        ]);

        return $validated;
    }
}
