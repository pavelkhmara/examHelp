<?php

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\GenerationPlan;
use App\Models\Question;
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
        protected readonly QuestionAttacher $attacher,
        protected readonly QuestionGroupGenerationService $questionGroupService
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
            // Create async AI provider (respect AI_PROVIDER from config)
            $providerName = config('ai.provider', 'openai');
            $asyncProvider = AiProviderFactory::makeAsync($providerName, config('ai'));

            // ✅ FIX: Save requests map for accessing opts later (contains _questions_by_group)
            $requestsMap = [];
            foreach ($requests as $request) {
                $requestsMap[$request['key']] = $request;
            }

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

                // ✅ FIX: Get opts with _questions_by_group metadata
                $opts = $requestsMap[$key]['opts'] ?? [];

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

                    // ✅ FIX: Add group_id from opts to parsed questions
                    // AI doesn't return group_id (not in JSON schema), so we restore it from filter metadata
                    $questionsByGroup = $opts['_questions_by_group'] ?? [];
                    if (! empty($questionsByGroup)) {
                        foreach ($questions as $index => &$question) {
                            // Match question by index with filter metadata
                            if (isset($questionsByGroup[$index])) {
                                $filterMetadata = $questionsByGroup[$index];

                                // Propagate group_id and question_id from filter
                                if (isset($filterMetadata['group_id'])) {
                                    $question['group_id'] = $filterMetadata['group_id'];
                                }
                                if (isset($filterMetadata['question_id'])) {
                                    $question['id'] = $filterMetadata['question_id'];
                                }
                            }
                        }
                        unset($question); // Break reference

                        Log::info('[ParallelQuestionSynthesizer] Added group_id from filter metadata', [
                            'plan_id' => $plan->id,
                            'questions_count' => count($questions),
                            'with_group_id' => count(array_filter($questions, fn ($q) => ! empty($q['group_id']))),
                        ]);
                    }

                    // DEBUG: Check parsed questions
                    dump([
                        'parseAndValidateQuestions' => 'success',
                        'questions_count' => count($questions),
                        'first_question' => $questions[0] ?? null,
                    ]);

                    // Validate and deduplicate
                    $validatedQuestions = $this->questionValidator->validateAndFinalize($questions, $plan, $exam);

                    // DEBUG: Check validated questions
                    dump([
                        'validateAndFinalize' => 'done',
                        'validated_count' => count($validatedQuestions),
                    ]);

                    $dedupedQuestions = $this->deduplicator->detectDuplicates($validatedQuestions, $exam);

                    // Check if we should create QuestionGroup (blueprint or inline mode with question_groups)
                    $attachedCount = 0;
                    $questionGroups = $plan->plan_data['question_groups'] ?? [];

                    // Inline mode with explicit question_groups
                    if (! empty($questionGroups) && ! empty($dedupedQuestions)) {
                        Log::info('[ParallelQuestionSynthesizer] Creating QuestionGroups from plan_data', [
                            'plan_id' => $plan->id,
                            'section_id' => $plan->section_id,
                            'groups_count' => count($questionGroups),
                            'questions_count' => count($dedupedQuestions),
                        ]);

                        $attachedCount = $this->createQuestionGroupsFromPlanData(
                            $plan,
                            $exam,
                            $questionGroups,
                            $dedupedQuestions
                        );
                        $attachedQuestions = ['groups_created'];
                    }
                    // Blueprint mode with shared_stimulus
                    elseif ($plan->assembly_mode === 'blueprint' && ! empty($dedupedQuestions)) {
                        $slots = $plan->plan_data['slots'] ?? [];
                        $firstSlot = $slots[0] ?? [];

                        // Check if should create QuestionGroup
                        if ($this->shouldCreateQuestionGroup($firstSlot, count($dedupedQuestions))) {
                            // Create QuestionGroup instead of separate Questions
                            Log::info('[ParallelQuestionSynthesizer] Creating QuestionGroup for blueprint', [
                                'plan_id' => $plan->id,
                                'section_id' => $plan->section_id,
                                'questions_count' => count($dedupedQuestions),
                            ]);

                            $questionGroup = $this->createQuestionGroupFromBlueprint(
                                $plan,
                                $exam,
                                $firstSlot,
                                $dedupedQuestions,
                                0
                            );

                            $attachedCount = $questionGroup->questions()->count();
                            $attachedQuestions = [$questionGroup->group_id]; // For logging
                        } else {
                            // Create separate Questions (current behavior)
                            $attachedQuestions = $this->attacher->attachToExam($dedupedQuestions, $plan, $exam);
                            $attachedCount = count($attachedQuestions);
                        }
                    } else {
                        // Not blueprint mode, or inline/pool mode without question_groups - use normal attachment
                        $attachedQuestions = $this->attacher->attachToExam($dedupedQuestions, $plan, $exam);
                        $attachedCount = count($attachedQuestions);
                    }

                    // Log validation result
                    if ($task) {
                        $this->log(
                            task: $task,
                            stage: "synthesis_{$sectionName}_validated",
                            request: ['questions_raw' => count($questions)],
                            response: [
                                'validated' => count($validatedQuestions),
                                'deduped' => count($dedupedQuestions),
                                'attached' => $attachedCount,
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
                        'attached' => $attachedCount,
                    ];

                    Log::info('[ParallelQuestionSynthesizer] Section completed', [
                        'plan_id' => $plan->id,
                        'questions_attached' => $attachedCount,
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

        // Special instructions for audio-based question types
        $audioInstructions = '';
        if (in_array($questionType, ['listen_mcq', 'dictation'])) {
            $audioInstructions = " CRITICAL FOR AUDIO TYPES: The stimulus.text_html field MUST contain the ACTUAL SPOKEN TEXT (dialogue, monologue, or sentence) that would be read aloud. DO NOT write meta-descriptions like 'Fragment dyskusji o...' or 'Nagranie zawiera...'. DO NOT include prefixes like 'Nagranie:', 'Audio:', 'Wysłuchaj:', 'Odtwórz nagranie'. DO NOT include playback instructions. Generate realistic conversation/speech text that test-takers would HEAR. For dialogues, use speaker labels (e.g., 'Ekspert 1: ... Professor: ...'). For monologues, provide full spoken text as paragraphs.";
        }

        $userPrompt = "Generate exactly {$totalQuestions} questions of type {$questionType} for a {$examLanguage} language exam at {$exam->level} level. ALL user-facing content (instructions, stimulus, options) MUST be in {$examLanguage}.{$audioInstructions} Return ONLY a valid JSON array with {$totalQuestions} question objects.";

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
     *
     * Inline mode supports two data structures:
     * 1. Legacy: plan_data.placeholders[] - flat list of questions
     * 2. New: plan_data.question_groups[].questions[] - grouped questions with shared stimulus
     */
    protected function prepareInlineRequest(GenerationPlan $plan, Exam $exam, array $section): array
    {
        $planData = $plan->plan_data;
        $examLanguage = \App\Support\LanguageHelper::getLanguageName($exam->language_of_test);
        $examLevel = $exam->level ?? 'B2';

        // NEW: Check for question_groups first (inline + question_groups mode)
        $questionGroups = $planData['question_groups'] ?? [];
        if (! empty($questionGroups)) {
            return $this->prepareInlineRequestWithGroups($plan, $exam, $section, $questionGroups);
        }

        // LEGACY: Fall back to placeholders
        $placeholders = $planData['placeholders'] ?? [];
        $totalQuestions = count($placeholders);

        if ($totalQuestions === 0) {
            Log::warning('[ParallelQuestionSynthesizer] No placeholders or question_groups in inline plan', [
                'plan_id' => $plan->id,
                'plan_data_keys' => array_keys($planData),
            ]);
        }

        // For simplicity, generate all inline questions in one AI request
        $firstPlaceholder = $placeholders[0] ?? [];
        $questionType = $firstPlaceholder['type'] ?? 'single_select';
        $config = $firstPlaceholder['config'] ?? [];

        $model = $this->selectModelForType($questionType);

        $prompt = PromptQuestionSynthesis::build(
            questionType: $questionType,
            archetypeConfig: $config,
            sectionSkill: $section['skill'],
            examLanguage: $examLanguage,
            examLevel: $examLevel,
            quantity: $totalQuestions,
            filters: null,
            contextHint: "Generate questions for inline mode ({$totalQuestions} questions total)"
        );

        // Special instructions for audio-based question types
        $audioInstructions = $this->getAudioInstructions($questionType);

        $userPrompt = "Generate exactly {$totalQuestions} questions of type {$questionType} for a {$examLanguage} language exam at {$examLevel} level. ALL user-facing content (instructions, stimulus, options) MUST be in {$examLanguage}.{$audioInstructions} Return ONLY a valid JSON array with {$totalQuestions} question objects.";

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
                    $examLanguage,
                    $examLevel,
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
     * Prepare AI request for inline mode with question_groups
     *
     * Generates questions for each group separately, considering:
     * - Shared stimulus (audio/text) for the group
     * - Different question types within a group
     * - Group context for coherent question generation
     */
    protected function prepareInlineRequestWithGroups(
        GenerationPlan $plan,
        Exam $exam,
        array $section,
        array $questionGroups
    ): array {
        $examLanguage = \App\Support\LanguageHelper::getLanguageName($exam->language_of_test);
        $examLevel = $exam->level ?? 'B2';

        // Extract all questions from all groups with their context
        $allQuestions = [];
        $groupContexts = [];

        foreach ($questionGroups as $groupIndex => $group) {
            $groupId = $group['id'] ?? "group_{$groupIndex}";
            $groupTitle = $group['title'] ?? '';
            $groupStimulus = $group['stimulus'] ?? [];
            $groupQuestions = $group['questions'] ?? [];

            $groupContexts[$groupId] = [
                'title' => $groupTitle,
                'stimulus' => $groupStimulus,
                'question_count' => count($groupQuestions),
            ];

            foreach ($groupQuestions as $qIndex => $q) {
                $allQuestions[] = [
                    'question_id' => $q['id'] ?? "q{$qIndex}",  // ✅ СОХРАНЯЕМ ID из plan_data
                    'type' => $q['type'] ?? 'single_select',
                    'config' => $q['config'] ?? [],
                    'group_id' => $groupId,
                    'group_title' => $groupTitle,
                    'group_stimulus' => $groupStimulus,
                    'order_in_group' => $qIndex,
                ];
            }
        }

        $totalQuestions = count($allQuestions);

        Log::info('[ParallelQuestionSynthesizer] Preparing inline request with question_groups', [
            'plan_id' => $plan->id,
            'groups_count' => count($questionGroups),
            'total_questions' => $totalQuestions,
            'group_contexts' => $groupContexts,
        ]);

        if ($totalQuestions === 0) {
            Log::warning('[ParallelQuestionSynthesizer] No questions in question_groups', [
                'plan_id' => $plan->id,
            ]);

            return [
                'payload' => [],
                'opts' => [],
            ];
        }

        // Group questions by type for more efficient generation
        $questionsByType = [];
        foreach ($allQuestions as $q) {
            $type = $q['type'];
            if (! isset($questionsByType[$type])) {
                $questionsByType[$type] = [];
            }
            $questionsByType[$type][] = $q;
        }

        // For now, generate all questions in one request
        // Use the most common type as primary, with context about groups
        $primaryType = array_key_first($questionsByType);
        $model = $this->selectModelForType($primaryType);

        // Build detailed prompt with group information
        $groupDescriptions = [];
        foreach ($questionGroups as $group) {
            $groupId = $group['id'] ?? '';
            $groupTitle = $group['title'] ?? '';
            $groupQuestions = $group['questions'] ?? [];
            $stimulus = $group['stimulus'] ?? [];

            $stimulusDesc = '';
            if (! empty($stimulus['audio'])) {
                $stimulusDesc = 'shared audio stimulus';
            } elseif (! empty($stimulus['text_html'])) {
                $stimulusDesc = 'shared text stimulus';
            }

            $questionTypes = array_map(fn ($q) => $q['type'] ?? 'unknown', $groupQuestions);
            $typeCounts = array_count_values($questionTypes);
            $typeDesc = implode(', ', array_map(fn ($t, $c) => "{$c}x {$t}", array_keys($typeCounts), $typeCounts));

            $groupDescriptions[] = "- Group \"{$groupTitle}\" ({$groupId}): {$typeDesc}".($stimulusDesc ? " with {$stimulusDesc}" : '');
        }

        $audioInstructions = $this->getAudioInstructions($primaryType);

        $contextHint = "Generate questions for inline mode with question groups:\n".implode("\n", $groupDescriptions);

        $userPrompt = <<<PROMPT
Generate exactly {$totalQuestions} questions for a {$examLanguage} language exam at {$examLevel} level.

The questions are organized in groups with shared stimulus:
{$contextHint}

IMPORTANT:
- Generate questions in the EXACT order they appear in the groups
- Questions in the same group share the same stimulus context
- ALL user-facing content (instructions, stimulus, options) MUST be in {$examLanguage}
{$audioInstructions}

Return ONLY a valid JSON array with {$totalQuestions} question objects.
PROMPT;

        return [
            'payload' => [
                'exam_title' => $exam->title,
                'input' => $userPrompt,
                'user_input' => $userPrompt,
            ],
            'opts' => [
                'prompt_class' => PromptQuestionSynthesis::class,
                'prompt_args' => [
                    $primaryType,
                    [], // config
                    $section['skill'],
                    $examLanguage,
                    $examLevel,
                    $totalQuestions,
                    null,
                    $contextHint,
                ],
                'model' => $model,
                'json_schema' => QuestionArraySchema::getSchema($primaryType),
                'json_schema_name' => 'question_generation',
                // Pass group info for post-processing
                '_question_groups' => $questionGroups,
                '_questions_by_group' => $allQuestions,
            ],
        ];
    }

    /**
     * Get audio-specific instructions for AI prompt
     */
    protected function getAudioInstructions(string $questionType): string
    {
        if (in_array($questionType, ['listen_mcq', 'listen_true_false', 'listen_yes_no_ng', 'dictation'])) {
            return " CRITICAL FOR AUDIO TYPES: The stimulus.text_html field MUST contain the ACTUAL SPOKEN TEXT (dialogue, monologue, or sentence) that would be read aloud. DO NOT write meta-descriptions like 'Fragment dyskusji o...' or 'Nagranie zawiera...'. DO NOT include prefixes like 'Nagranie:', 'Audio:', 'Wysłuchaj:', 'Odtwórz nagranie'. DO NOT include playback instructions. Generate realistic conversation/speech text that test-takers would HEAR. For dialogues, use speaker labels (e.g., 'Ekspert 1: ... Professor: ...'). For monologues, provide full spoken text as paragraphs.";
        }

        return '';
    }

    /**
     * Get section metadata from ExamCategory (PRIMARY source of truth)
     *
     * FIX: Changed to read from ExamCategory instead of structure_v2
     * See: docs/fixes/fix-dual-source-of-truth.md
     */
    protected function getSectionMetadata(Exam $exam, int $categoryId): array
    {
        $category = \App\Models\ExamCategory::find($categoryId);

        if (! $category) {
            throw new \Exception("ExamCategory not found: {$categoryId}");
        }

        return $this->buildSectionFromCategory($category);
    }

    /**
     * Build section metadata array from ExamCategory model
     */
    protected function buildSectionFromCategory(\App\Models\ExamCategory $category): array
    {
        $meta = $category->meta ?? [];

        return [
            'id' => $category->key ?? "sec-{$category->id}",
            'title' => $category->name,
            'skill' => $category->skill,
            'duration_min' => $category->duration_min,
            'max_score' => $category->max_score,
            'description' => $category->description,
            'questions' => $meta['questions'] ?? [],
            'assembly' => $meta['assembly'] ?? [],
            'question_archetypes' => $meta['question_archetypes'] ?? [],
        ];
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

    /**
     * Infer shared_stimulus for backward compatibility with old structures
     *
     * @param  array  $slot  Blueprint slot configuration
     * @return bool Whether questions should share a stimulus
     */
    protected function inferSharedStimulus(array $slot): bool
    {
        // Get question type from filters
        $type = $slot['filters']['type'][0] ?? null;

        // Inference rules based on question type
        return match ($type) {
            'listen_mcq', 'dictation' => true,  // Audio-based: usually shared stimulus
            'writing_prompt', 'speaking_prompt' => false,  // Always separate
            default => false  // Default: separate questions
        };
    }

    /**
     * Check if blueprint slot should create a QuestionGroup
     *
     * @param  array  $slot  Blueprint slot from plan_data
     * @param  int  $questionsCount  Number of questions to generate
     */
    protected function shouldCreateQuestionGroup(array $slot, int $questionsCount): bool
    {
        // Get shared_stimulus from slot (with backward compatibility)
        $sharedStimulus = $slot['shared_stimulus'] ?? $this->inferSharedStimulus($slot);

        // QuestionGroup requires shared stimulus AND multiple questions (2+)
        return $sharedStimulus && $questionsCount >= 2;
    }

    /**
     * Create a QuestionGroup from blueprint slot and generated questions
     *
     * @param  GenerationPlan  $plan  Generation plan
     * @param  Exam  $exam  Exam model
     * @param  array  $slot  Blueprint slot configuration
     * @param  array  $questions  AI-generated questions
     * @param  int  $groupOrder  Order of this group within section
     */
    protected function createQuestionGroupFromBlueprint(
        GenerationPlan $plan,
        Exam $exam,
        array $slot,
        array $questions,
        int $groupOrder = 0
    ): QuestionGroup {
        // Build group spec from slot and questions
        $slotId = $slot['slot'] ?? 'group_'.$groupOrder;
        $stimulusType = $slot['stimulus_type'] ?? 'audio';

        // Generate group title from slot (or use default)
        $title = $slot['title'] ?? ucfirst($slotId);

        // Extract stimulus from first question (if present)
        $groupStimulus = [];
        if (! empty($questions)) {
            $firstQuestionStimulus = $questions[0]['stimulus'] ?? [];
            if (! empty($firstQuestionStimulus)) {
                $groupStimulus = $firstQuestionStimulus;
            }
        }

        // Build playback settings for audio stimulus
        $playbackSettings = null;
        if ($stimulusType === 'audio' && isset($slot['playback_settings'])) {
            $playbackSettings = $slot['playback_settings'];
        }

        // Build group spec
        $groupSpec = [
            'id' => $slotId,
            'title' => $title,
            'stimulus' => $groupStimulus,
            'playback_settings' => $playbackSettings,
            'questions' => $questions,
            'order' => $groupOrder,
        ];

        Log::info('[ParallelQuestionSynthesizer] Creating QuestionGroup from blueprint', [
            'slot_id' => $slotId,
            'questions_count' => count($questions),
            'stimulus_type' => $stimulusType,
        ]);

        // Create QuestionGroup using QuestionGroupGenerationService
        return $this->questionGroupService->generateQuestionGroup($plan, $groupSpec, $groupOrder);
    }

    /**
     * Create multiple QuestionGroups from plan_data.question_groups
     *
     * @param  GenerationPlan  $plan  Generation plan
     * @param  Exam  $exam  Exam model
     * @param  array  $groupConfigs  Question group configurations from plan_data
     * @param  array  $questions  AI-generated questions to distribute
     * @return int Total questions attached
     */
    protected function createQuestionGroupsFromPlanData(
        GenerationPlan $plan,
        Exam $exam,
        array $groupConfigs,
        array $questions
    ): int {
        $totalAttached = 0;
        $questionIndex = 0;

        foreach ($groupConfigs as $order => $groupConfig) {
            $groupId = $groupConfig['id'] ?? 'group_'.$order;
            $questionsForGroup = $groupConfig['questions'] ?? [];
            $questionsCount = count($questionsForGroup);

            // Take questions for this group from generated questions
            $groupQuestions = array_slice($questions, $questionIndex, $questionsCount);
            $questionIndex += $questionsCount;

            if (empty($groupQuestions)) {
                Log::warning('[ParallelQuestionSynthesizer] No questions for group', [
                    'group_id' => $groupId,
                    'expected' => $questionsCount,
                ]);

                continue;
            }

            // Build group spec
            $groupSpec = [
                'id' => $groupId,
                'title' => $groupConfig['title'] ?? ucfirst($groupId),
                'stimulus' => $groupConfig['stimulus'] ?? [],
                'playback_settings' => $groupConfig['playback_settings'] ?? null,
                'questions' => $groupQuestions,
                'order' => $order,
            ];

            Log::info('[ParallelQuestionSynthesizer] Creating QuestionGroup from plan_data', [
                'group_id' => $groupId,
                'questions_count' => count($groupQuestions),
            ]);

            // Create QuestionGroup using service
            $questionGroup = $this->questionGroupService->generateQuestionGroup($plan, $groupSpec, $order);
            $totalAttached += $questionGroup->questions()->count();
        }

        // Attach remaining ungrouped questions
        if ($questionIndex < count($questions)) {
            $remainingQuestions = array_slice($questions, $questionIndex);
            $attachedQuestions = $this->attacher->attachToExam($remainingQuestions, $plan, $exam);
            $totalAttached += count($attachedQuestions);

            Log::info('[ParallelQuestionSynthesizer] Attached remaining ungrouped questions', [
                'count' => count($attachedQuestions),
            ]);
        }

        // ✅ SYNC: Update exam.meta.generated_questions_v2 after all groups created
        $this->syncGeneratedQuestionsToExamMeta($plan, $exam, $questions);

        return $totalAttached;
    }

    /**
     * Synchronize generated questions to exam.meta.generated_questions_v2
     * (replicates QuestionAttacher logic for question_groups flow)
     *
     * @param  array  $questions  Generated questions array
     */
    protected function syncGeneratedQuestionsToExamMeta(GenerationPlan $plan, Exam $exam, array $questions): void
    {
        if (empty($questions)) {
            return;
        }

        // Build map of question_id → group_id from database
        // (questions are already created with question_group_id)
        $questionRecords = Question::where('exam_id', $exam->id)
            ->where('section_id', $plan->section_id)
            ->whereNotNull('question_group_id')
            ->with('questionGroup')
            ->get();

        $groupIdMap = [];
        foreach ($questionRecords as $record) {
            // Extract raw question ID (without group prefix)
            $questionId = $record->question_id;
            $rawId = $questionId;

            if ($record->questionGroup) {
                $groupId = $record->questionGroup->group_id;
                if (str_starts_with($questionId, $groupId.'_')) {
                    $rawId = substr($questionId, strlen($groupId) + 1);
                }
                $groupIdMap[$rawId] = $groupId;
            }
        }

        // Add group_id to each question in the array
        $questionsWithGroupId = [];
        foreach ($questions as $question) {
            $questionId = $question['id'] ?? null;
            $question['group_id'] = $groupIdMap[$questionId] ?? null;
            $questionsWithGroupId[] = $question;
        }

        // Update exam meta with generated questions
        $meta = $exam->meta ?? [];
        $existingQuestions = $meta['generated_questions_v2'] ?? [];

        // Merge with existing (avoid duplicates by 'id')
        $merged = collect($existingQuestions);
        foreach ($questionsWithGroupId as $newQuestion) {
            $existingIndex = $merged->search(fn ($q) => ($q['id'] ?? null) === ($newQuestion['id'] ?? null));
            if ($existingIndex !== false) {
                // Replace existing question
                $merged[$existingIndex] = $newQuestion;
            } else {
                // Add new question
                $merged->push($newQuestion);
            }
        }

        $meta['generated_questions_v2'] = $merged->values()->toArray();
        $exam->meta = $meta;
        $exam->save();

        Log::info('[ParallelQuestionSynthesizer] Synced generated_questions_v2 to exam.meta', [
            'exam_id' => $exam->id,
            'section_id' => $plan->section_id,
            'questions_synced' => count($questionsWithGroupId),
            'with_group_id' => count(array_filter($questionsWithGroupId, fn ($q) => ! empty($q['group_id']))),
            'total_in_meta' => count($meta['generated_questions_v2']),
        ]);
    }
}
