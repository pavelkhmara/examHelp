<?php

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\GenerationLog;
use App\Models\GenerationPlan;
use App\Services\LanguageApp\Prompts\PromptQuestionSynthesis;
use App\Services\LanguageApp\Validators\JsonSchemaQuestionV2;
use Illuminate\Support\Facades\Log;

/**
 * Question Synthesizer Service (Stage 6)
 *
 * Generates exam questions based on GenerationPlan configurations from Phase B.
 * Supports three assembly modes: pool, blueprint, inline.
 *
 * Uses GPT-5 for complex question types, GPT-5 Mini for simple types.
 */
class QuestionSynthesizer extends AbstractAiService
{
    /**
     * Complex question types requiring GPT-5
     */
    private const COMPLEX_TYPES = [
        'essay', 'writing_prompt', 'speaking_prompt',
        'matching', 'diagram_labeling', 'graph_description',
        'translation', 'roleplay',
    ];

    /**
     * Simple question types suitable for GPT-5 Mini
     */
    private const SIMPLE_TYPES = [
        'single_select', 'multi_select', 'true_false', 'yes_no_ng',
        'short_answer', 'numeric', 'dictation',
        'gap_cloze', 'dropdown_cloze',
    ];

    public function __construct(
        AiProvider $ai,
        protected readonly JsonSchemaQuestionV2 $validator
    ) {
        parent::__construct($ai);
    }

    /**
     * Main synthesis entry point - routes to appropriate mode handler
     *
     * @return array Array of generated questions
     *
     * @throws \Exception
     */
    public function synthesize(GenerationPlan $plan, Exam $exam): array
    {
        Log::info('[QuestionSynthesizer] Starting synthesis', [
            'plan_id' => $plan->id,
            'section_id' => $plan->section_id,
            'mode' => $plan->assembly_mode,
            'total_questions' => $plan->total_questions,
        ]);

        try {
            // Mark plan as in progress
            $plan->markAsInProgress();

            // Route to appropriate handler based on assembly mode
            $questions = match ($plan->assembly_mode) {
                'pool' => $this->synthesizeFromPool($plan, $exam),
                'blueprint' => $this->synthesizeFromBlueprint($plan, $exam),
                'inline' => $this->synthesizeInline($plan, $exam),
                default => throw new \Exception("Unknown assembly mode: {$plan->assembly_mode}"),
            };

            // Mark plan as completed
            $plan->markAsCompleted();

            Log::info('[QuestionSynthesizer] Synthesis completed', [
                'plan_id' => $plan->id,
                'generated_count' => count($questions),
            ]);

            return $questions;
        } catch (\Throwable $e) {
            // Mark plan as failed
            $plan->markAsFailed($e->getMessage());

            Log::error('[QuestionSynthesizer] Synthesis failed', [
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Synthesize questions from POOL mode
     *
     * Generates questions by "sampling" from a virtual pool with filters.
     * NOTE: Currently pools don't exist yet - AI generates all questions from scratch.
     *
     * FIXED (24.11.2025): Pass section instead of single archetype to generateQuestionBatch
     */
    protected function synthesizeFromPool(GenerationPlan $plan, Exam $exam): array
    {
        $planData = $plan->plan_data;
        $poolId = $planData['pool_id'] ?? 'default';
        $filters = $planData['filters'] ?? [];
        $pick = $planData['pick'] ?? $plan->total_questions;

        Log::info('[QuestionSynthesizer] Pool mode synthesis', [
            'pool_id' => $poolId,
            'pick' => $pick,
            'filters' => $filters,
        ]);

        // Get section metadata from exam structure
        $section = $this->getSectionMetadata($exam, $plan->section_id);

        // ✅ FIX: Pass section instead of single archetype
        $questions = $this->generateQuestionBatch(
            $exam,
            $section,  // ← Changed: Pass entire section
            $section['skill'],
            $pick,
            $filters,
            $plan
        );

        return $questions;
    }

    /**
     * Synthesize questions from BLUEPRINT mode
     *
     * Generates questions for each slot in the blueprint.
     * Each slot specifies: from_pool, filters, pick.
     *
     * FIXED (24.11.2025): Pass section instead of single archetype to generateQuestionBatch
     */
    protected function synthesizeFromBlueprint(GenerationPlan $plan, Exam $exam): array
    {
        $planData = $plan->plan_data;
        $slots = $planData['slots'] ?? [];

        Log::info('[QuestionSynthesizer] Blueprint mode synthesis', [
            'slots_count' => count($slots),
        ]);

        $allQuestions = [];
        $section = $this->getSectionMetadata($exam, $plan->section_id);

        foreach ($slots as $slotIndex => $slot) {
            $poolId = $slot['from_pool'] ?? 'default';
            $filters = $slot['filters'] ?? [];
            $pick = $slot['pick'] ?? 0;

            Log::debug('[QuestionSynthesizer] Processing blueprint slot', [
                'slot_index' => $slotIndex,
                'pool_id' => $poolId,
                'pick' => $pick,
                'filters' => $filters,
            ]);

            // ✅ FIX: Pass section instead of single archetype
            $slotQuestions = $this->generateQuestionBatch(
                $exam,
                $section,  // ← Changed: Pass entire section
                $section['skill'],
                $pick,
                $filters,
                $plan
            );

            $allQuestions = array_merge($allQuestions, $slotQuestions);

            // Update progress
            $plan->incrementGenerated(count($slotQuestions));
        }

        return $allQuestions;
    }

    /**
     * Synthesize questions from INLINE mode
     *
     * Generates specific questions for each placeholder.
     * Each placeholder has explicit type and configuration.
     */
    protected function synthesizeInline(GenerationPlan $plan, Exam $exam): array
    {
        $planData = $plan->plan_data;
        $placeholders = $planData['placeholders'] ?? [];

        Log::info('[QuestionSynthesizer] Inline mode synthesis', [
            'placeholders_count' => count($placeholders),
        ]);

        $allQuestions = [];
        $section = $this->getSectionMetadata($exam, $plan->section_id);

        foreach ($placeholders as $placeholderIndex => $placeholder) {
            $type = $placeholder['type'] ?? 'single_select';
            $config = $placeholder['config'] ?? [];

            Log::debug('[QuestionSynthesizer] Processing inline placeholder', [
                'placeholder_index' => $placeholderIndex,
                'type' => $type,
                'config' => $config,
            ]);

            // Generate single question for this placeholder
            $question = $this->generateSingleQuestion(
                $exam,
                $type,
                $config,
                $section['skill'],
                $plan
            );

            $allQuestions[] = $question;

            // Update progress
            $plan->incrementGenerated(1);
        }

        return $allQuestions;
    }

    /**
     * Generate a batch of questions (for pool/blueprint modes)
     *
     * IMPORTANT: For pool mode, iterates through filters and generates
     * questions of DIFFERENT TYPES as specified in each filter.
     *
     * NOTE: Generates questions ONE AT A TIME for better reliability
     *
     * FIXED (24.11.2025): Now gets archetype for EACH filter type instead of using same archetype
     *
     * VISIBILITY: Changed to public for SynthesizeTaskQuestionsJob access (task-level parallelization)
     */
    public function generateQuestionBatch(
        Exam $exam,
        array $section,
        string $sectionSkill,
        int $quantity,
        array $filters,
        GenerationPlan $plan
    ): array {
        $allQuestions = [];

        // If filters is empty, fall back to first archetype
        if (empty($filters)) {
            $archetype = $section['question_archetypes'][0] ?? [];
            $questionType = $archetype['type'] ?? 'single_select';

            Log::debug('[QuestionSynthesizer] No filters provided, using first archetype', [
                'type' => $questionType,
                'quantity' => $quantity,
            ]);

            // Generate all questions of same type
            for ($i = 1; $i <= $quantity; $i++) {
                try {
                    $question = $this->generateSingleQuestion(
                        $exam,
                        $questionType,
                        $archetype,
                        $sectionSkill,
                        $plan,
                        null
                    );

                    $allQuestions[] = $question;
                } catch (\Throwable $e) {
                    Log::error('[QuestionSynthesizer] Failed to generate question', [
                        'index' => $i,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } else {
            // FIXED: Get archetype for EACH filter type
            Log::info('[QuestionSynthesizer] Pool mode: Generating questions from filters', [
                'filter_count' => count($filters),
            ]);

            foreach ($filters as $filterIndex => $filter) {
                $filterType = $filter['type'] ?? 'single_select';
                $filterPick = $filter['pick'] ?? 1;

                // ✅ FIX: Get archetype matching THIS filter's type
                $archetype = $this->getArchetypeByType($section, $filterType);

                Log::debug('[QuestionSynthesizer] Processing filter with matched archetype', [
                    'index' => $filterIndex,
                    'type' => $filterType,
                    'pick' => $filterPick,
                    'archetype_id' => $archetype['id'] ?? 'unknown',
                ]);

                // Generate {pick} questions of {type}
                for ($i = 1; $i <= $filterPick; $i++) {
                    try {
                        $question = $this->generateSingleQuestion(
                            $exam,
                            $filterType,
                            $archetype,  // ← Now matches filter type!
                            $sectionSkill,
                            $plan,
                            $filter
                        );

                        $allQuestions[] = $question;

                        Log::debug('[QuestionSynthesizer] Generated question from filter', [
                            'filter_index' => $filterIndex,
                            'question_index' => $i,
                            'of' => $filterPick,
                            'type' => $filterType,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('[QuestionSynthesizer] Failed to generate question from filter', [
                            'filter_index' => $filterIndex,
                            'question_index' => $i,
                            'type' => $filterType,
                            'error' => $e->getMessage(),
                        ]);
                        // Continue with remaining questions instead of failing completely
                    }
                }
            }
        }

        if (empty($allQuestions)) {
            throw new \Exception('No valid questions generated in batch');
        }

        Log::info('[QuestionSynthesizer] Batch generation completed', [
            'expected' => $quantity,
            'actual' => count($allQuestions),
            'filters_used' => !empty($filters),
        ]);

        return $allQuestions;
    }

    /**
     * Generate a single question (for inline mode)
     *
     * @param  Exam  $exam
     * @param  string  $questionType  Type of question to generate
     * @param  array  $config  Archetype configuration
     * @param  string  $sectionSkill  Section skill (reading, listening, etc.)
     * @param  GenerationPlan  $plan
     * @param  array|null  $filter  Optional filter specification (type, difficulty, tags, etc.)
     */
    protected function generateSingleQuestion(
        Exam $exam,
        string $questionType,
        array $config,
        string $sectionSkill,
        GenerationPlan $plan,
        ?array $filter = null
    ): array {
        $model = $this->selectModelForType($questionType);

        Log::debug('[QuestionSynthesizer] Generating single question', [
            'type' => $questionType,
            'model' => $model,
            'filter' => $filter,
        ]);

        // Build prompt
        $prompt = PromptQuestionSynthesis::build(
            questionType: $questionType,
            archetypeConfig: $config,
            sectionSkill: $sectionSkill,
            examLanguage: \App\Support\LanguageHelper::getLanguageName($exam->language_of_test),
            examLevel: $exam->level ?? 'B2',
            quantity: 1,
            filters: $filter,  // Pass filter to prompt (was null before)
            contextHint: null
        );

        // Call AI with clear instructions
        $userPrompt = "Generate exactly 1 question of type {$questionType}. Return ONLY a valid JSON array with 1 question object, no markdown, no explanations.";

        $payload = [
            'exam_title' => $exam->title,
            'input' => $userPrompt,
            'user_input' => $userPrompt,
        ];

        $opts = [
            'prompt_class' => PromptQuestionSynthesis::class,
            'prompt_args' => [
                $questionType,
                $config,
                $sectionSkill,
                \App\Support\LanguageHelper::getLanguageName($exam->language_of_test),
                $exam->level ?? 'B2',
                1,
                null,
                null,
            ],
            'model' => $model,
        ];

        $result = $this->callAi($payload, $opts);

        // Log AI request
        $this->logGeneration($plan, $exam, 'question_synthesis_inline', $result);

        // Parse and validate questions
        $questions = $this->parseAndValidateQuestions($result['content_text'] ?? '');

        if (empty($questions)) {
            throw new \Exception('Failed to generate question - empty result');
        }

        return $questions[0];
    }

    /**
     * Parse AI response and validate questions
     *
     * Handles multiple response formats:
     * 1. Flat array: [{"id": "q1"}, {"id": "q2"}]
     * 2. Nested object: {"content": {"questions": [...]}}
     * 3. Numeric keys: {"0": {...}, "1": {...}}
     * 4. Single object: {"id": "q1", ...}
     *
     * FIXED (24.11.2025): Enhanced to handle nested objects from AI
     */
    protected function parseAndValidateQuestions(string $content): array
    {
        // Extract JSON from markdown code blocks if present
        $content = $this->extractJsonFromMarkdown($content);

        // Parse JSON
        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON response from AI: '.json_last_error_msg());
        }

        if (! is_array($decoded)) {
            throw new \Exception('AI response is not an array');
        }

        // ✅ FIX 1: Handle nested {"content": {"questions": [...]}}
        if (isset($decoded['content'])) {
            Log::debug('[QuestionSynthesizer] Unwrapping nested content object');

            if (isset($decoded['content']['questions']) && is_array($decoded['content']['questions'])) {
                $decoded = $decoded['content']['questions'];
            } elseif (is_array($decoded['content'])) {
                $decoded = $decoded['content'];
            }
        }

        // ✅ FIX 2: Handle {"questions": [...}} at top level
        if (isset($decoded['questions']) && is_array($decoded['questions'])) {
            Log::debug('[QuestionSynthesizer] Unwrapping questions array');
            $decoded = $decoded['questions'];
        }

        // ✅ FIX 3: Handle numeric keys {"0": {...}, "1": {...}}
        if (isset($decoded[0]) && is_array($decoded[0])) {
            // Already a flat array, ensure numeric keys
            $decoded = array_values($decoded);
        }

        // ✅ FIX 4: Handle single object {"id": "q1", ...}
        if ($this->isAssoc($decoded) && isset($decoded['id'])) {
            Log::debug('[QuestionSynthesizer] Wrapping single question object');
            $decoded = [$decoded];
        }

        // ✅ FIX 5: Final validation - must be array of objects
        if (!is_array($decoded) || empty($decoded)) {
            throw new \Exception('Failed to extract questions array from AI response');
        }

        // Validate each question
        $validatedQuestions = [];
        foreach ($decoded as $index => $question) {
            if (!is_array($question)) {
                Log::warning('[QuestionSynthesizer] Skipping non-array item', [
                    'index' => $index,
                    'type' => gettype($question),
                ]);
                continue;
            }

            try {
                $validated = $this->validator->validate($question);
                $validatedQuestions[] = $validated;
            } catch (\Throwable $e) {
                Log::error('[QuestionSynthesizer] Question validation failed', [
                    'index' => $index,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'question' => json_encode($question, JSON_PRETTY_PRINT),
                ]);
                // Continue with other questions instead of failing completely
            }
        }

        if (empty($validatedQuestions)) {
            throw new \Exception('No valid questions generated - all failed validation');
        }

        Log::info('[QuestionSynthesizer] Parsed and validated questions', [
            'total_parsed' => count($decoded),
            'validated' => count($validatedQuestions),
            'failed' => count($decoded) - count($validatedQuestions),
        ]);

        return $validatedQuestions;
    }

    /**
     * Extract JSON from markdown code blocks (```json ... ```)
     */
    protected function extractJsonFromMarkdown(string $content): string
    {
        // Try to find JSON in markdown code block
        if (preg_match('/```json\s*(.*?)\s*```/s', $content, $matches)) {
            return trim($matches[1]);
        }

        // Try to find any code block
        if (preg_match('/```\s*(.*?)\s*```/s', $content, $matches)) {
            return trim($matches[1]);
        }

        // Return as is if no code blocks found
        return trim($content);
    }

    /**
     * Check if array is associative
     */
    protected function isAssoc(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * Select appropriate AI model based on question type complexity
     */
    protected function selectModelForType(string $type): string
    {
        if (in_array($type, self::COMPLEX_TYPES)) {
            return 'thinking'; // GPT-5 (AI_MODEL_THINKING)
        }

        return 'default'; // GPT-5 Mini (AI_MODEL)
    }

    /**
     * Get section metadata from exam structure
     */
    protected function getSectionMetadata(Exam $exam, string|int $sectionId): array
    {
        $structure = $exam->meta['structure_v2'] ?? [];
        $sections = $structure['sections'] ?? [];

        // If $sectionId is numeric, it's an ExamCategory ID - match by skill
        if (is_numeric($sectionId)) {
            $category = \App\Models\ExamCategory::find($sectionId);
            if (!$category) {
                throw new \Exception("ExamCategory not found for ID: {$sectionId}");
            }

            // Match by skill (more reliable than key)
            $skill = $category->skill;
            foreach ($sections as $section) {
                if (($section['skill'] ?? null) === $skill) {
                    return $section;
                }
            }

            // Fallback: try matching by key if skill match fails
            $sectionKey = $category->key ?? $category->name;
            foreach ($sections as $section) {
                if ($section['id'] === $sectionKey) {
                    return $section;
                }
            }

            throw new \Exception("Section not found for skill '{$skill}' or key '{$sectionKey}' (ExamCategory ID: {$sectionId})");
        }

        // String sectionId - match by section ID
        foreach ($sections as $section) {
            if ($section['id'] === $sectionId) {
                return $section;
            }
        }

        throw new \Exception("Section not found: {$sectionId}");
    }

    /**
     * Get archetype matching specific question type
     *
     * @param array $section Section metadata with question_archetypes
     * @param string $questionType Question type (single_select, multi_select, etc.)
     * @return array Archetype configuration
     * @throws \Exception If no matching archetype found
     */
    protected function getArchetypeByType(array $section, string $questionType): array
    {
        $archetypes = $section['question_archetypes'] ?? [];

        if (empty($archetypes)) {
            throw new \Exception('No archetypes found in section');
        }

        // Find archetype matching the question type
        foreach ($archetypes as $archetype) {
            if (($archetype['type'] ?? null) === $questionType) {
                Log::debug('[QuestionSynthesizer] Found matching archetype', [
                    'question_type' => $questionType,
                    'archetype_id' => $archetype['id'] ?? 'unknown',
                ]);
                return $archetype;
            }
        }

        // Fallback: Use first archetype as template
        Log::warning('[QuestionSynthesizer] No exact archetype match, using first', [
            'question_type' => $questionType,
            'available_types' => array_column($archetypes, 'type'),
        ]);

        return $archetypes[0];
    }

    /**
     * Get archetype configuration for pool-based generation
     *
     * @deprecated Use getArchetypeByType() instead - this always returns first archetype
     */
    protected function getArchetypeForPool(array $section, array $filters): array
    {
        // For pool mode, derive archetype from section's archetypes
        $archetypes = $section['question_archetypes'] ?? [];

        if (empty($archetypes)) {
            throw new \Exception('No archetypes found in section');
        }

        // Use first archetype as template (simplified for Stage 6)
        // In future, filter by difficulty/topic/etc
        return $archetypes[0];
    }

    /**
     * Log generation request to database
     */
    protected function logGeneration(GenerationPlan $plan, Exam $exam, string $stage, array $result): void
    {
        try {
            GenerationLog::create([
                'exam_id' => $exam->id,
                'generation_task_id' => null, // No task for direct plan execution
                'stage' => $stage,
                'request' => $result['sent_messages'] ?? [],
                'response' => [
                    'content' => $result['content'] ?? null,
                    'model' => $result['model'] ?? null,
                ],
                'prompt_tokens' => $result['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $result['usage']['completion_tokens'] ?? 0,
                'total_tokens' => $result['usage']['total_tokens'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[QuestionSynthesizer] Failed to create generation log', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
