<?php

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\ExamDocument;
use App\Models\GenerationLog;
use App\Models\GenerationTask;
use App\Services\LanguageApp\Prompts\PromptConfidenceBoost;
use Illuminate\Support\Facades\Log;

/**
 * Service for boosting confidence in exam identity
 *
 * Responsibilities:
 * - Boost confidence from 0.90-0.96 to >0.97 through family ruleset verification
 * - Returns updated identity result with new confidence and explanation
 */
class ConfidenceBoostService extends AbstractAiService
{
    /**
     * Stage: confidence_boost
     * - Boosts confidence from 0.90-0.96 to >0.97 through family ruleset verification
     * - Returns updated identity result with new confidence and explanation
     */
    public function runConfidenceBoost(Exam $exam, GenerationTask $task, array $identityResult): array
    {
        $req = (array) ($task->request ?? []);
        $user = (array) ($req['user_input'] ?? []);
        $docId = $req['document_id'] ?? null;

        // Extract document text
        $extracted = $this->extractDocumentText($docId);

        // Load family ruleset
        $family = $identityResult['canonical']['family'] ?? null;
        $familyRuleset = $this->loadFamilyRuleset($family);

        if (! $familyRuleset) {
            Log::warning('Confidence boost skipped: missing family or ruleset', [
                'family' => $family,
                'exam_id' => $exam->id,
            ]);

            return $identityResult;
        }

        // Build AI payload
        $aiPayload = PromptConfidenceBoost::buildPayload(
            $identityResult,
            $familyRuleset,
            $user,
            $extracted
        );

        try {
            // Call AI provider
            $aiResponse = $this->ai->generate($aiPayload, [
                'json_schema' => PromptConfidenceBoost::schema(),
            ]);

            // Extract content
            $content = $this->extractContent($aiResponse);

            if (! is_array($content)) {
                throw new \RuntimeException('AI response did not contain valid JSON array');
            }

            // Merge boost result with original identity
            $boostedIdentity = $this->mergeBoostedIdentity($identityResult, $content);

            // Log
            $this->logBoostResult($exam, $task, $family, $identityResult['confidence'], $boostedIdentity, $aiResponse);

            return $boostedIdentity;
        } catch (\Throwable $e) {
            Log::warning('Confidence boost failed, using original identity', [
                'error' => $e->getMessage(),
                'exam_id' => $exam->id,
                'task_id' => $task->id,
            ]);

            // Log failed attempt
            $this->logBoostFailure($exam, $task, $identityResult, $e->getMessage());

            return $identityResult;
        }
    }

    /**
     * Extract document text from document ID
     */
    protected function extractDocumentText(?int $docId): ?string
    {
        if (! $docId) {
            return null;
        }

        $doc = ExamDocument::query()->find($docId);
        if (! $doc || ! is_string($doc->extracted_text) || $doc->extracted_text === '') {
            return null;
        }

        return $doc->extracted_text;
    }

    /**
     * Load family ruleset from config
     */
    protected function loadFamilyRuleset(?string $family): ?array
    {
        if (! $family) {
            return null;
        }

        $rulesetPath = config_path('family_ruleset.json');
        if (! file_exists($rulesetPath)) {
            return null;
        }

        $ruleset = json_decode(file_get_contents($rulesetPath), true);

        return $ruleset[$family] ?? null;
    }

    /**
     * Extract content from AI response
     */
    protected function extractContent(array $aiResponse): mixed
    {
        if (isset($aiResponse['content'])) {
            return is_string($aiResponse['content'])
                ? json_decode($aiResponse['content'], true)
                : $aiResponse['content'];
        }

        if (isset($aiResponse['body']['choices'][0]['message']['content'])) {
            $raw = $aiResponse['body']['choices'][0]['message']['content'];

            return is_string($raw) ? json_decode($raw, true) : $raw;
        }

        return null;
    }

    /**
     * Merge boosted identity with original
     */
    protected function mergeBoostedIdentity(array $identityResult, array $content): array
    {
        $boostedIdentity = array_merge($identityResult, [
            'confidence' => $content['confidence'] ?? $identityResult['confidence'],
            'status' => $content['status'] ?? $identityResult['status'],
            'confidence_boost' => [
                'original_confidence' => $identityResult['confidence'],
                'boosted_confidence' => $content['confidence'] ?? $identityResult['confidence'],
                'adjustment_reason' => $content['adjustment_reason'] ?? '',
                'checks_performed' => $content['checks_performed'] ?? [],
                'evidence_quality' => $content['evidence_quality'] ?? 'unknown',
                'recommendation' => $content['recommendation'] ?? 'request_user_confirmation',
            ],
        ]);

        // Update hold based on new confidence
        if ($boostedIdentity['confidence'] >= 0.97 && $boostedIdentity['status'] === 'certain') {
            $boostedIdentity['hold'] = true;
        } else {
            $boostedIdentity['hold'] = false;
        }

        return $boostedIdentity;
    }

    /**
     * Log boost result
     */
    protected function logBoostResult(
        Exam $exam,
        GenerationTask $task,
        ?string $family,
        float $originalConfidence,
        array $boostedIdentity,
        array $aiResponse
    ): void {
        GenerationLog::create([
            'exam_id' => $exam->id,
            'generation_task_id' => $task->id,
            'stage' => 'confidence_boost',
            'request' => [
                'original_confidence' => $originalConfidence,
                'family' => $family,
            ],
            'response' => $boostedIdentity,
            'prompt_tokens' => $aiResponse['usage']['prompt_tokens'] ?? 0,
            'completion_tokens' => $aiResponse['usage']['completion_tokens'] ?? 0,
            'total_tokens' => $aiResponse['usage']['total_tokens'] ?? 0,
        ]);
    }

    /**
     * Log boost failure
     */
    protected function logBoostFailure(Exam $exam, GenerationTask $task, array $identityResult, string $error): void
    {
        GenerationLog::create([
            'exam_id' => $exam->id,
            'generation_task_id' => $task->id,
            'stage' => 'confidence_boost_failed',
            'request' => [
                'original_confidence' => $identityResult['confidence'],
                'error' => $error,
            ],
            'response' => $identityResult,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ]);
    }
}
