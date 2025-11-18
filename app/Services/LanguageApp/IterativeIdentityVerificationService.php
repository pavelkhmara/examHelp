<?php

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\GenerationTask;
use Illuminate\Support\Facades\Log;

/**
 * Iterative Identity Verification Service
 *
 * Manages the iterative process of exam identity verification:
 * - Max 3 attempts to identify and verify the exam
 * - Max 6 minutes timeout for the entire verification process
 * - Generates followup questions when confidence is low
 * - Uses web search to gather additional information
 * - Adapts prompts based on previous results
 * - Tracks progress in task activities
 */
class IterativeIdentityVerificationService extends AbstractAiService
{
    const MAX_ATTEMPTS = 3;
    const MAX_DURATION_SECONDS = 360; // 6 minutes
    const MIN_CONFIDENCE_THRESHOLD = 0.80; // Lowered from 0.94 to 0.90 - with documents, 90%+ is reliable enough

    public function __construct(
        AiProvider $ai,
        protected IdentityGuardService $identityGuard,
        protected WebSearchService $webSearch
    ) {
        parent::__construct($ai);
    }

    /**
     * Run iterative identity verification
     *
     * @param Exam $exam
     * @param GenerationTask $task
     * @return array Identity result with final verdict
     */
    public function runIterativeVerification(Exam $exam, GenerationTask $task): array
    {
        $startTime = time();
        $attempt = 1;
        $history = [];
        $webSearchData = [];

        $task->addActivity('iterative_verification_started', 'Starting iterative identity verification', [
            'max_attempts' => self::MAX_ATTEMPTS,
            'max_duration_seconds' => self::MAX_DURATION_SECONDS,
            'min_confidence' => self::MIN_CONFIDENCE_THRESHOLD,
        ]);

        while ($attempt <= self::MAX_ATTEMPTS) {
            // Check timeout
            $elapsed = time() - $startTime;
            if ($elapsed >= self::MAX_DURATION_SECONDS) {
                return $this->handleTimeout($exam, $task, $history, $webSearchData, $elapsed);
            }

            $task->addActivity("verification_attempt_{$attempt}", "Starting verification attempt {$attempt}/{" . self::MAX_ATTEMPTS . "}", [
                'attempt' => $attempt,
                'elapsed_seconds' => $elapsed,
            ]);

            // Prepare enhanced user input for this attempt (without modifying task)
            $enhancedUserInput = $this->prepareEnhancedUserInput($task, $history, $webSearchData);

            // Run identity guard with enhanced input
            $identityResult = $this->runIdentityGuardWithEnhancedInput($exam, $task, $enhancedUserInput);
            $confidence = $identityResult['confidence'] ?? 0.0;

            $task->addActivity("verification_attempt_{$attempt}_completed", "Attempt {$attempt} completed with confidence: {$confidence}", [
                'attempt' => $attempt,
                'confidence' => $confidence,
                'status' => $identityResult['status'] ?? 'unknown',
            ]);

            // Save to history
            $history[] = [
                'attempt' => $attempt,
                'identity_result' => $identityResult,
                'timestamp' => now()->toISOString(),
                'elapsed_seconds' => time() - $startTime,
            ];

            // Save history to task result
            $this->saveVerificationHistory($task, $history, $webSearchData);

            // Check if confidence is sufficient
            if ($confidence >= self::MIN_CONFIDENCE_THRESHOLD) {
                $task->addActivity('decision_point_iterative_verification', "Условие: confidence >= ".self::MIN_CONFIDENCE_THRESHOLD." (текущий: {$confidence}, попытка {$attempt}). Решение: верификация успешна, продолжить к ConfidenceBoost", [
                    'condition' => 'confidence >= '.self::MIN_CONFIDENCE_THRESHOLD,
                    'confidence' => $confidence,
                    'attempt' => $attempt,
                    'decision' => 'verification_success_continue',
                ]);

                $task->addActivity('verification_success', "Identity verified successfully with confidence: {$confidence}", [
                    'total_attempts' => $attempt,
                    'elapsed_seconds' => time() - $startTime,
                    'confidence' => $confidence,
                ]);

                return $identityResult;
            }

            // If this was the last attempt, fail
            if ($attempt >= self::MAX_ATTEMPTS) {
                $task->addActivity('decision_point_iterative_verification', "Условие: confidence < ".self::MIN_CONFIDENCE_THRESHOLD." И attempt >= ".self::MAX_ATTEMPTS." (текущий: {$confidence}, попытка {$attempt}). Решение: ОШИБКА - max_attempts_reached", [
                    'condition' => 'confidence < '.self::MIN_CONFIDENCE_THRESHOLD.' AND attempt >= '.self::MAX_ATTEMPTS,
                    'confidence' => $confidence,
                    'attempt' => $attempt,
                    'decision' => 'fail_max_attempts',
                ]);

                return $this->handleMaxAttemptsReached($exam, $task, $history, $webSearchData, $identityResult);
            }

            // Generate followup questions if not present
            $followups = $identityResult['followups'] ?? [];
            if (empty($followups)) {
                $followups = $this->generateFollowupQuestions($identityResult);
            }

            if (empty($followups)) {
                // No followups means we can't improve - stop here
                return $this->handleNoFollowups($exam, $task, $history, $webSearchData, $identityResult);
            }

            // Check if this is first attempt - if so, STOP and request user clarification
            // (unless this attempt already has user clarification from previous pause)
            // IMPORTANT: Check for clarification in both old ('clarification_answers') and new ('clarification') format
            // Also check the saved flag from task.result['identity']['user_provided_clarification']
            $hasUserClarification = !empty($task->request['user_input']['clarification_answers'] ?? [])
                || !empty($task->request['user_input']['clarification'] ?? '')
                || !empty($task->result['identity']['user_provided_clarification'] ?? false);

            \Illuminate\Support\Facades\Log::info('🔍 [ITERATIVE] Checking user clarification', [
                'attempt' => $attempt,
                'hasUserClarification' => $hasUserClarification,
                'has_clarification_answers' => !empty($task->request['user_input']['clarification_answers'] ?? []),
                'has_clarification' => !empty($task->request['user_input']['clarification'] ?? ''),
                'has_flag' => !empty($task->result['identity']['user_provided_clarification'] ?? false),
            ]);

            if ($attempt === 1 && !$hasUserClarification) {
                // First attempt with low confidence and no user clarification yet
                // Save followup questions and pause for user input
                $task->addActivity('decision_point_iterative_verification', "Условие: confidence < ".self::MIN_CONFIDENCE_THRESHOLD." И attempt = 1 И НЕТ user_clarification (текущий: {$confidence}, попытка {$attempt}). Решение: PAUSE - ожидание ответов на вопросы от пользователя", [
                    'condition' => 'confidence < '.self::MIN_CONFIDENCE_THRESHOLD.' AND attempt = 1 AND NO user_clarification',
                    'confidence' => $confidence,
                    'attempt' => $attempt,
                    'decision' => 'pause_pending_clarification',
                ]);

                $task->addActivity("verification_attempt_{$attempt}_followups", "Generated " . count($followups) . " followup questions - waiting for user", [
                    'followups' => $followups,
                ]);

                // Save history and followup questions
                $this->saveVerificationHistory($task, $history, $webSearchData);

                // Save followup questions in identity result for card display
                // ProvideAnswersAction expects them at task->result['identity']['followups']
                $result = (array)($task->result ?? []);
                $result['identity'] = array_merge($identityResult, [
                    'followups' => $followups,
                    'status' => 'needs_clarification',
                ]);
                $task->result = $result;
                $task->save();

                // Return special status to indicate we need user clarification
                return [
                    'status' => 'needs_clarification',
                    'confidence' => $confidence,
                    'canonical' => $identityResult['canonical'] ?? [],
                    'followups' => $followups,
                    'message' => 'Please answer the following questions to help us identify your exam.',
                ];
            }

            // Decision: confidence < threshold, attempt < max, have followups -> run WebSearch and retry
            $task->addActivity('decision_point_iterative_verification', "Условие: confidence < ".self::MIN_CONFIDENCE_THRESHOLD." И attempt < ".self::MAX_ATTEMPTS." (текущий: {$confidence}, попытка {$attempt}). Решение: запустить WebSearch и повторить IdentityGuard (попытка ".($attempt + 1).")", [
                'condition' => 'confidence < '.self::MIN_CONFIDENCE_THRESHOLD.' AND attempt < '.self::MAX_ATTEMPTS,
                'confidence' => $confidence,
                'attempt' => $attempt,
                'decision' => 'run_web_search_retry',
            ]);

            $task->addActivity("verification_attempt_{$attempt}_followups", "Generated " . count($followups) . " followup questions", [
                'followups' => $followups,
            ]);

            // Use web search to find answers
            $webSearchResults = $this->searchForAnswers($exam, $task, $identityResult, $followups);

            $task->addActivity("verification_attempt_{$attempt}_web_search", "Completed web search for answers", [
                'results_count' => count($webSearchResults),
            ]);

            // Store web search results (don't add to user_input)
            $webSearchData = array_merge($webSearchData, $webSearchResults);

            $attempt++;

            // Check timeout again before next iteration
            $elapsed = time() - $startTime;
            if ($elapsed >= self::MAX_DURATION_SECONDS) {
                return $this->handleTimeout($exam, $task, $history, $webSearchData, $elapsed);
            }
        }

        // Should not reach here, but just in case
        return $this->handleMaxAttemptsReached($exam, $task, $history, $webSearchData, $history[count($history) - 1]['identity_result'] ?? []);
    }

    /**
     * Generate followup questions based on identity result
     */
    protected function generateFollowupQuestions(array $identityResult): array
    {
        $followups = [];
        $canonical = $identityResult['canonical'] ?? [];
        $needFields = $identityResult['need_fields'] ?? [];

        // Check what's missing
        if (empty($canonical['family'])) {
            $followups[] = [
                'q' => 'What is the exact name or code of the exam? (e.g., IELTS, TOEFL, CCE-A2, Goethe-Zertifikat B1)',
                'why' => 'Need to identify exam family',
            ];
        }

        if (empty($canonical['variant']) && !empty($canonical['family'])) {
            $family = $canonical['family'];
            if (str_contains($family, 'IELTS')) {
                $followups[] = [
                    'q' => 'Is this IELTS Academic or General Training?',
                    'why' => 'Variant affects test structure and content',
                ];
            } elseif (str_contains($family, 'TOEFL')) {
                $followups[] = [
                    'q' => 'Is this TOEFL iBT, TOEFL iBT Home Edition, or TOEFL Paper Edition?',
                    'why' => 'Format affects delivery and timing',
                ];
            }
        }

        if (in_array('language', $needFields)) {
            $followups[] = [
                'q' => 'What language is being tested in this exam?',
                'why' => 'Need to identify test language',
            ];
        }

        if (in_array('target.level', $needFields)) {
            $followups[] = [
                'q' => 'What is the target CEFR level? (A1, A2, B1, B2, C1, C2)',
                'why' => 'Level determines test complexity and structure',
            ];
        }

        return $followups;
    }

    /**
     * Search for answers using web search
     */
    protected function searchForAnswers(Exam $exam, GenerationTask $task, array $identityResult, array $followups): array
    {
        $results = [];
        $canonical = $identityResult['canonical'] ?? [];
        $family = $canonical['family'] ?? '';
        $variant = $canonical['variant'] ?? '';

        // Build search queries based on what we know
        $queries = [];

        if (!empty($family)) {
            $queries[] = "{$family} exam structure format sections";

            if (!empty($variant)) {
                $queries[] = "{$family} {$variant} official guide";
            } else {
                $queries[] = "{$family} variants types differences";
            }
        }

        // Add queries from followups
        foreach ($followups as $followup) {
            if (isset($followup['q'])) {
                // Extract key terms from question
                $query = $this->extractSearchQueryFromQuestion($followup['q'], $family);
                if ($query) {
                    $queries[] = $query;
                }
            }
        }

        // Execute searches
        foreach (array_slice($queries, 0, 3) as $query) { // Limit to 3 searches max
            try {
                $searchResult = $this->webSearch->search($query);
                $results[] = [
                    'query' => $query,
                    'result' => $searchResult,
                ];
            } catch (\Throwable $e) {
                Log::warning('Web search failed', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Extract search query from followup question
     */
    protected function extractSearchQueryFromQuestion(string $question, string $family): ?string
    {
        $question = strtolower($question);

        if (str_contains($question, 'academic') || str_contains($question, 'general training')) {
            return "{$family} Academic vs General Training differences";
        }

        if (str_contains($question, 'ibt') || str_contains($question, 'paper edition')) {
            return "{$family} iBT vs Paper Edition format";
        }

        if (str_contains($question, 'level') || str_contains($question, 'cefr')) {
            return "{$family} CEFR levels structure";
        }

        if (str_contains($question, 'variant') || str_contains($question, 'module')) {
            return "{$family} variants modules types";
        }

        return "{$family} exam format structure";
    }

    /**
     * Prepare enhanced user input for this attempt
     * Returns a temporary enhanced copy without modifying the task
     */
    protected function prepareEnhancedUserInput(GenerationTask $task, array $history, array $webSearchData): array
    {
        $req = (array)($task->request ?? []);
        $userInput = (array)($req['user_input'] ?? []);

        // Build iteration context
        if (!empty($history)) {
            $previousAttempts = [];
            foreach ($history as $item) {
                $previousAttempts[] = [
                    'attempt' => $item['attempt'],
                    'confidence' => $item['identity_result']['confidence'] ?? 0,
                    'status' => $item['identity_result']['status'] ?? 'unknown',
                    'canonical' => $item['identity_result']['canonical'] ?? [],
                    'issues' => $item['identity_result']['followups'] ?? [],
                ];
            }

            $userInput['iteration_context'] = [
                'iteration_number' => count($history) + 1,
                'previous_attempts' => $previousAttempts,
            ];
        }

        // Add web search data
        if (!empty($webSearchData)) {
            $userInput['web_search_data'] = $webSearchData;
        }

        return $userInput;
    }

    /**
     * Run identity guard with enhanced input
     * Creates a temporary task copy to avoid polluting the original
     */
    protected function runIdentityGuardWithEnhancedInput(Exam $exam, GenerationTask $task, array $enhancedUserInput): array
    {
        // Create a temporary task with enhanced input
        $tempTask = clone $task;
        $req = (array)($task->request ?? []);
        $req['user_input'] = $enhancedUserInput;
        $tempTask->request = $req;

        // Run identity guard with temp task
        return $this->identityGuard->runIdentityGuard($exam, $tempTask);
    }

    /**
     * Save verification history to task result
     */
    protected function saveVerificationHistory(GenerationTask $task, array $history, array $webSearchData): void
    {
        $result = (array)($task->result ?? []);
        $result['verification_attempts'] = $history;

        if (!empty($webSearchData)) {
            $result['web_search_data'] = $webSearchData;
        }

        $task->result = $result;
        $task->save();
    }

    /**
     * Handle timeout scenario
     */
    protected function handleTimeout(Exam $exam, GenerationTask $task, array $history, array $webSearchData, int $elapsed): array
    {
        $task->addActivity('verification_timeout', "Verification timeout after {$elapsed} seconds", [
            'elapsed_seconds' => $elapsed,
            'max_seconds' => self::MAX_DURATION_SECONDS,
            'total_attempts' => count($history),
        ]);

        $lastResult = !empty($history) ? $history[count($history) - 1]['identity_result'] : [];

        // Save final history
        $this->saveVerificationHistory($task, $history, $webSearchData);

        return [
            'status' => 'failed',
            'confidence' => $lastResult['confidence'] ?? 0,
            'canonical' => $lastResult['canonical'] ?? [],
            'error' => 'timeout',
            'user_message' => 'We were unable to verify your exam within the time limit (6 minutes). This may be because the exam information is not clear or not available online. Please provide more specific details about your exam.',
        ];
    }

    /**
     * Handle max attempts reached
     */
    protected function handleMaxAttemptsReached(Exam $exam, GenerationTask $task, array $history, array $webSearchData, array $lastResult): array
    {
        $task->addActivity('verification_max_attempts', 'Max verification attempts reached', [
            'total_attempts' => count($history),
            'final_confidence' => $lastResult['confidence'] ?? 0,
        ]);

        $confidence = $lastResult['confidence'] ?? 0;

        // Generate user-friendly message based on what went wrong
        $userMessage = $this->generateFailureMessage($lastResult, $history);

        // Save final history
        $this->saveVerificationHistory($task, $history, $webSearchData);

        return [
            'status' => 'failed',
            'confidence' => $confidence,
            'canonical' => $lastResult['canonical'] ?? [],
            'error' => 'max_attempts_reached',
            'user_message' => $userMessage,
        ];
    }

    /**
     * Handle no followups scenario
     */
    protected function handleNoFollowups(Exam $exam, GenerationTask $task, array $history, array $webSearchData, array $lastResult): array
    {
        $task->addActivity('verification_no_followups', 'No followup questions available - cannot improve confidence', [
            'total_attempts' => count($history),
            'final_confidence' => $lastResult['confidence'] ?? 0,
        ]);

        // Save final history
        $this->saveVerificationHistory($task, $history, $webSearchData);

        return [
            'status' => 'failed',
            'confidence' => $lastResult['confidence'] ?? 0,
            'canonical' => $lastResult['canonical'] ?? [],
            'error' => 'no_improvement_possible',
            'user_message' => 'We were unable to verify your exam with sufficient confidence. The information provided is insufficient or unclear. Please provide more specific details about your exam, such as the exact exam name, provider, or official exam code.',
        ];
    }

    /**
     * Generate user-friendly failure message
     */
    protected function generateFailureMessage(array $lastResult, array $history): string
    {
        $confidence = $lastResult['confidence'] ?? 0;
        $family = $lastResult['canonical']['family'] ?? null;
        $needFields = $lastResult['need_fields'] ?? [];

        if (empty($family)) {
            return 'We could not identify your exam. Please provide the exact exam name or code (e.g., IELTS Academic, TOEFL iBT, Goethe-Zertifikat B2, CCE-A2).';
        }

        if ($confidence < 0.5) {
            return "We found some information about {$family}, but couldn't verify it with sufficient confidence. This may be because:\n" .
                   "- The exam variant is unclear (e.g., Academic vs General, iBT vs Paper)\n" .
                   "- There is limited information available online\n" .
                   "- The exam name or code is not specific enough\n\n" .
                   "Please provide more details or contact support.";
        }

        if (!empty($needFields)) {
            $missingFields = implode(', ', $needFields);
            return "We identified the exam as {$family}, but need more information: {$missingFields}. Please provide these details to continue.";
        }

        return "We were unable to verify your exam with sufficient confidence (current: " . round($confidence * 100) . "%, required: 94%). " .
               "This may be because the exam information is ambiguous or not available online. Please provide more specific details.";
    }
}
