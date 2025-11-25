<?php

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\Validators\JsonSchemaExamMetadata;
use Illuminate\Support\Facades\Log;

/**
 * Service for analyzing exam metadata from user input
 * Extracts user information and exam identity details using AI
 */
class ExamMetadataAnalysisService extends AbstractAiService
{
    public function __construct(
        AiProvider $ai,
        protected readonly JsonSchemaExamMetadata $validator
    ) {
        parent::__construct($ai);
    }

    /**
     * Analyze exam metadata from user input, description, and identity fields
     *
     * @param Exam $exam The exam to analyze
     * @param GenerationTask|null $task Optional task for logging
     * @return array Analysis result with user_meta, identity updates, and system_analysis
     */
    public function analyze(Exam $exam, ?GenerationTask $task = null): array
    {
        Log::info("ExamMetadataAnalysisService: Starting analysis for exam {$exam->id}");

        // Step 1: Check if user_input is valid JSON and parse it
        $parsedUserInput = $this->parseUserInput($exam->user_input);

        // Step 2: Determine if we need AI analysis
        $needsAiAnalysis = $this->needsAiAnalysis($exam, $parsedUserInput);

        if (!$needsAiAnalysis && !empty($parsedUserInput)) {
            // We have complete parsed JSON, no AI needed
            Log::info("ExamMetadataAnalysisService: Complete JSON provided, skipping AI");

            // Build system analysis with change tracking even without AI
            $oldUserMeta = $exam->user_meta ?? [];
            $newUserMeta = $parsedUserInput;
            $systemAnalysis = [
                'warning' => [],
                'critical' => [],
                'info' => ['Metadata extracted from provided JSON without AI analysis'],
                'changes' => [],
                'confidence' => 1.0,
            ];

            // Track changes
            $this->trackUserMetaChanges($oldUserMeta, $newUserMeta, $systemAnalysis);

            return [
                'user_meta' => $newUserMeta,
                'identity' => array_merge($exam->identity ?? [], $this->extractIdentityFromParsed($parsedUserInput)),
                'system_analysis' => $systemAnalysis,
            ];
        }

        // Step 3: Gather all available information
        $sources = $this->gatherSources($exam, $parsedUserInput);

        // Step 4: Call AI
        $aiResponse = $this->callAiForAnalysis($exam, $sources, $task);

        // Step 5: Validate response
        try {
            $validated = $this->validator->validate($aiResponse);
        } catch (\Exception $e) {
            Log::error("ExamMetadataAnalysisService: Validation failed", [
                'error' => $e->getMessage(),
                'response' => $aiResponse,
            ]);

            return [
                'user_meta' => $parsedUserInput ?? [],
                'identity' => $exam->identity ?? [],
                'system_analysis' => [
                    'critical' => ['AI response validation failed: ' . $e->getMessage()],
                ],
            ];
        }

        // Step 6: Build system analysis with change tracking
        $newUserMeta = $validated['user_meta'] ?? [];
        $oldUserMeta = $exam->user_meta ?? [];
        $systemAnalysis = $this->buildSystemAnalysis($validated, $exam, $sources, $oldUserMeta, $newUserMeta);

        // Step 7: Merge identity with existing data
        $mergedIdentity = array_merge(
            $exam->identity ?? [],
            $validated['identity'] ?? []
        );

        Log::info("ExamMetadataAnalysisService: Analysis completed", [
            'user_meta_keys' => array_keys($newUserMeta),
            'identity_keys' => array_keys($mergedIdentity),
        ]);

        return [
            'user_meta' => $newUserMeta,
            'identity' => $mergedIdentity,
            'system_analysis' => $systemAnalysis,
        ];
    }

    /**
     * Parse user_input if it's valid JSON
     */
    private function parseUserInput(?string $userInput): ?array
    {
        if (empty($userInput)) {
            return null;
        }

        $trimmed = trim($userInput);
        if (!str_starts_with($trimmed, '{') && !str_starts_with($trimmed, '[')) {
            return null;
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                Log::info("ExamMetadataAnalysisService: Parsed user_input as JSON");
                return $decoded;
            }
        } catch (\JsonException $e) {
            Log::debug("ExamMetadataAnalysisService: user_input is not valid JSON");
        }

        return null;
    }

    /**
     * Determine if AI analysis is needed
     */
    private function needsAiAnalysis(Exam $exam, ?array $parsedUserInput): bool
    {
        // Required fields for complete metadata
        $requiredUserMeta = ['user_language', 'exam_language', 'exam_purpose'];
        $requiredIdentity = ['exam_name', 'exam_family'];

        // Check if parsed JSON has all required fields
        if (!empty($parsedUserInput)) {
            $hasAllUserMeta = !array_diff($requiredUserMeta, array_keys($parsedUserInput));
            $hasAllIdentity = !array_diff($requiredIdentity, array_keys($parsedUserInput));

            if ($hasAllUserMeta && $hasAllIdentity) {
                return false; // Complete data, no AI needed
            }
        }

        // If we have any text input or incomplete JSON, we need AI
        return !empty($exam->user_input) || !empty($exam->description) || !empty($parsedUserInput);
    }

    /**
     * Extract identity fields from parsed user input
     */
    private function extractIdentityFromParsed(array $parsed): array
    {
        $identity = [];

        $identityKeys = ['exam_family', 'exam_name', 'exam_code', 'provider', 'country', 'exam_language'];
        foreach ($identityKeys as $key) {
            if (isset($parsed[$key])) {
                $identity[$key] = $parsed[$key];
            }
        }

        return $identity;
    }

    /**
     * Gather all available information sources
     */
    private function gatherSources(Exam $exam, ?array $parsedUserInput): array
    {
        $sources = [];

        // User input (original text)
        if (!empty($exam->user_input)) {
            $sources['user_input'] = $exam->user_input;
        }

        // Parsed user input (if JSON)
        if (!empty($parsedUserInput)) {
            $sources['parsed_user_input'] = $parsedUserInput;
        }

        // Description
        if (!empty($exam->description)) {
            $sources['description'] = $exam->description;
        }

        // Existing identity fields
        if (!empty($exam->identity)) {
            $sources['existing_identity'] = $exam->identity;
        }

        // Title and level
        $sources['exam_title'] = $exam->title;
        $sources['exam_level'] = $exam->level;

        return $sources;
    }

    /**
     * Call AI for metadata analysis
     */
    private function callAiForAnalysis(Exam $exam, array $sources, ?GenerationTask $task): array
    {
        $prompt = Prompts\PromptMetadataAnalysis::build($sources);

        $payload = [
            'exam_title' => $exam->title,
            'exam_level' => $exam->level,
            'input' => json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $prompt,
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'exam_metadata_analysis',
                    'strict' => true,
                    'schema' => JsonSchemaExamMetadata::getSchema(),
                ],
            ],
        ];

        $opts = [
            'model' => config('ai.model'), // Use gpt-5-mini for this task
        ];

        Log::debug("ExamMetadataAnalysisService: Calling AI", ['payload' => $payload]);

        $response = $this->ai->generate($payload, $opts);

        // Log to generation_logs if task provided
        if ($task) {
            $this->log($task, 'metadata_analysis', $payload, $response);
        }

        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException('AI call failed: ' . ($response['error'] ?? 'Unknown error'));
        }

        // OpenAiProvider returns 'content', not 'parsed'
        return $response['content'] ?? [];
    }

    /**
     * Build system analysis with warnings, criticals, and info
     * Tracks changes between old and new user_meta values
     */
    private function buildSystemAnalysis(array $validated, Exam $exam, array $sources, array $oldUserMeta, array $newUserMeta): array
    {
        $analysis = [
            'warning' => [],
            'critical' => [],
            'info' => [],
            'changes' => [],
        ];

        $confidence = $validated['confidence'] ?? 0;

        // Confidence checks
        if ($confidence >= 0.8) {
            $analysis['info'][] = sprintf('Confidence is very high - %.2f', $confidence);
        } elseif ($confidence < 0.5) {
            $analysis['warning'][] = 'Confidence too low - ' . sprintf('%.2f', $confidence);
        }

        // Check for conflicts between sources
        if (isset($sources['existing_identity']['exam_family']) && isset($validated['identity']['exam_family'])) {
            $existing = $sources['existing_identity']['exam_family'];
            $new = $validated['identity']['exam_family'];
            if ($existing !== $new && $existing !== 'Unknown') {
                $analysis['critical'][] = "Conflict: existing exam_family ($existing) vs AI analysis ($new)";
            }
        }

        // Check level consistency
        if (isset($validated['user_meta']['exam_level']) && $validated['user_meta']['exam_level'] !== $exam->level) {
            $analysis['warning'][] = sprintf(
                'Selected level (%s) differs from user input level (%s)',
                $exam->level,
                $validated['user_meta']['exam_level']
            );
        }

        // Track changes in user_meta
        $this->trackUserMetaChanges($oldUserMeta, $newUserMeta, $analysis);

        // Sources used
        $sourcesUsed = array_keys($sources);
        $analysis['info'][] = 'Sources analyzed: ' . implode(', ', $sourcesUsed);

        // Add AI confidence
        $analysis['confidence'] = $confidence;
        $analysis['analyzed_at'] = now()->toIso8601String();
        $analysis['ai_model'] = config('ai.model');

        return $analysis;
    }

    /**
     * Track changes between old and new user_meta values
     * Adds information about changes to the analysis array
     */
    private function trackUserMetaChanges(array $oldUserMeta, array $newUserMeta, array &$analysis): void
    {
        // Fields to track
        $trackedFields = [
            'user_language' => 'User Language',
            'user_gender' => 'User Gender',
            'exam_language' => 'Exam Language',
            'target_score' => 'Target Score',
            'exam_purpose' => 'Exam Purpose',
            'needs_certificate' => 'Needs Certificate',
            'exam_level' => 'Exam Level',
        ];

        foreach ($trackedFields as $field => $label) {
            $oldValue = $oldUserMeta[$field] ?? null;
            $newValue = $newUserMeta[$field] ?? null;

            // Skip if both are null
            if ($oldValue === null && $newValue === null) {
                continue;
            }

            // New field added
            if ($oldValue === null && $newValue !== null) {
                $analysis['changes'][] = [
                    'field' => $field,
                    'label' => $label,
                    'type' => 'added',
                    'new_value' => $newValue,
                ];
                $analysis['info'][] = sprintf('%s was set to: %s', $label, $this->formatValue($newValue));
                continue;
            }

            // Field removed
            if ($oldValue !== null && $newValue === null) {
                $analysis['changes'][] = [
                    'field' => $field,
                    'label' => $label,
                    'type' => 'removed',
                    'old_value' => $oldValue,
                ];
                $analysis['warning'][] = sprintf('%s was removed (was: %s)', $label, $this->formatValue($oldValue));
                continue;
            }

            // Field changed
            if ($oldValue !== $newValue) {
                $analysis['changes'][] = [
                    'field' => $field,
                    'label' => $label,
                    'type' => 'changed',
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                ];
                $analysis['info'][] = sprintf(
                    '%s changed from "%s" to "%s"',
                    $label,
                    $this->formatValue($oldValue),
                    $this->formatValue($newValue)
                );
            }
        }

        // If no changes, add info message
        if (empty($analysis['changes'])) {
            $analysis['info'][] = 'No changes to existing user metadata';
        }
    }

    /**
     * Format a value for display in messages
     */
    private function formatValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    /**
     * Log AI interaction to generation_logs table
     */
    protected function log(GenerationTask $task, string $stage, array $request, array $response): void
    {
        $usage = $response['usage'] ?? [];

        $task->exam->generationLogs()->create([
            'exam_id' => $task->exam->id,
            'generation_task_id' => $task->id,
            'stage' => $stage,
            'request' => $request,
            'response' => $response,
            'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? 0,
            'total_tokens' => $usage['total_tokens'] ?? 0,
        ]);
    }
}
