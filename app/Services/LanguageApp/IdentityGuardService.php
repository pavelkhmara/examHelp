<?php

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\ExamDocument;
use App\Models\GenerationLog;
use App\Models\GenerationTask;
use App\Services\LanguageApp\Prompts\PromptIdentityGuard;
use App\Services\LanguageApp\Validators\JsonSchemaExamIdentity;
use Illuminate\Support\Facades\Log;

/**
 * Service for exam identity verification
 *
 * Responsibilities:
 * - Run identity guard to determine exam family/provider/variant
 * - Run variant probe for disambiguation
 * - Fallback heuristics when AI unavailable
 */
class IdentityGuardService extends AbstractAiService
{
    /**
     * Stage: identity_guard
     * - Uses AI to analyze user_input + extracted_text
     * - Determines exam family, provider, variant with confidence
     * - Returns verdict + follow-ups if uncertain
     */
    public function runIdentityGuard(Exam $exam, GenerationTask $task): array
    {
        $req = (array) ($task->request ?? []);
        $user = (array) ($req['user_input'] ?? []);
        $docId = $req['document_id'] ?? null;

        // Extract document text if available
        $extracted = $this->extractDocumentText($docId);

        // Get file IDs for attachments (if enabled)
        $fileIds = $this->getDocumentFileIds($docId);

        // Load marker pack (placeholder for now - could be expanded later)
        $markerPack = [];

        // Get existing identity and user_meta from exam
        $existingIdentity = $exam->identity;
        $userMeta = $exam->user_meta;

        // Build AI payload using PromptIdentityGuard with existing data
        $aiPayload = PromptIdentityGuard::buildPayload($user, $extracted, $markerPack, $existingIdentity, $userMeta);

        try {
            // Call AI provider with file attachments if available
            $aiResponse = $this->ai->generate($aiPayload, [
                'json_schema' => PromptIdentityGuard::schema(),
                'file_ids' => $fileIds,
            ]);

            // Extract and validate content
            $content = $this->extractContent($aiResponse);

            if (! is_array($content)) {
                throw new \RuntimeException('AI response did not contain valid JSON array');
            }

            $res = $content;

            // Validate against schema
            /** @var JsonSchemaExamIdentity $schemaValidator */
            $schemaValidator = app(JsonSchemaExamIdentity::class);
            $res = $schemaValidator->validate($res);

            // Determine if we should set hold and boost flags
            $res = $this->setConfidenceFlags($res);

            // Log with tokens
            $this->logIdentityResult($exam, $task, $user, $docId, $extracted, $res, $aiPayload, $aiResponse);
        } catch (\Throwable $e) {
            // Fallback to heuristic method if AI fails
            Log::warning('Identity Guard AI failed, using fallback heuristics', [
                'error' => $e->getMessage(),
                'exam_id' => $exam->id,
                'task_id' => $task->id,
            ]);

            $res = $this->identityGuardFallback($exam, $user, $extracted);

            // Log fallback attempt
            $this->logFallbackResult($exam, $task, $user, $docId, $e->getMessage(), $res);
        }

        // Save identity result to task
        $result = (array) ($task->result ?? []);
        $result['identity'] = $res;
        $task->result = $result;
        $task->save();

        return $res;
    }

    /**
     * Stage: variant_probe
     * - Triggered when identity_guard confidence < 0.80 or multiple close candidates
     * - Disambiguates variant/module (Academic vs General, iBT vs Paper, B1 vs B2, etc.)
     * - Returns refined candidates + targeted follow-up questions
     */
    public function runVariantProbe(Exam $exam, GenerationTask $task, array $identityResult): array
    {
        $req = (array) ($task->request ?? []);
        $user = (array) ($req['user_input'] ?? []);
        $docId = $req['document_id'] ?? null;

        // Extract document text
        $extracted = $this->extractDocumentText($docId);

        $family = $identityResult['canonical']['family'] ?? null;
        $candidates = $identityResult['candidates'] ?? [];

        // Build variant-specific questions based on family
        $variantQuestions = $this->buildVariantQuestions($family, $user, $extracted);

        $result = [
            'family' => $family,
            'candidates' => $candidates,
            'followups' => $variantQuestions,
            'disambiguation_needed' => ! empty($variantQuestions),
        ];

        // Log
        GenerationLog::create([
            'exam_id' => $exam->id,
            'generation_task_id' => $task->id,
            'stage' => 'variant_probe',
            'request' => [
                'user_input' => $user,
                'identity_result' => $identityResult,
            ],
            'response' => $result,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ]);

        return $result;
    }

    /**
     * Fallback heuristic method when AI is unavailable
     */
    public function identityGuardFallback(Exam $exam, array $user, ?string $extracted): array
    {
        // IMPORTANT: We no longer block on missing user_meta fields
        // We try to extract what we can from available sources

        // Try to extract language from multiple sources
        $lang = $exam->language_of_test  // First: exam model field
            ?? $exam->identity['exam_language'] // Second: identity
            ?? $user['language'] // Third: user_meta
            ?? $user['exam_language']
            ?? null;

        // Try to extract country from multiple sources
        // Priority: identity (from AI analysis) → user input
        $country = $exam->identity['country'] ?? null;
        if (! $country) {
            $where = (array) ($user['where'] ?? []);
            $country = $where['country'] ?? null;
        }

        // Try to extract modality from user_meta (AI should determine this)
        // Priority: user_meta (from AI analysis) → user input
        $modality = $exam->user_meta['exam_modality'] ?? null;
        if (! $modality) {
            $where = (array) ($user['where'] ?? []);
            $modality = $where['mode'] ?? $where['modality'] ?? null;
        }

        // Try to extract level from exam model
        $target = (array) ($user['target'] ?? []);
        $level = $exam->level // First: exam model field
            ?? $target['level'] // Second: user_meta
            ?? null;
        $score = $target['score'] ?? null;

        // We DON'T block if these fields are missing - just log it
        if (! $lang) {
            Log::info('[IdentityGuardFallback] Language not found, will continue anyway');
        }
        if (! $country) {
            Log::info('[IdentityGuardFallback] Country not found, will continue anyway');
        }
        if (! $modality) {
            Log::info('[IdentityGuardFallback] Modality not found, will continue anyway');
        }

        // Simple keyword matching
        $hay = mb_strtolower(trim(
            ($user['exam_name'] ?? '').' '.
            ($exam->title ?? '').' '.
            ($user['notes'] ?? '').' '.
            ($extracted ? mb_substr($extracted, 0, 500) : '')
        ));

        $family = null;
        $provider = null;

        if (str_contains($hay, 'ielts')) {
            $family = 'IELTS';
            $provider = 'Cambridge/IDP/British Council';
        } elseif (str_contains($hay, 'toefl')) {
            $family = 'TOEFL iBT';
            $provider = 'ETS';
        } elseif (str_contains($hay, 'goethe')) {
            $family = 'Goethe-Zertifikat';
            $provider = 'Goethe-Institut';
        } elseif (str_contains($hay, 'delf') || str_contains($hay, 'dalf')) {
            $family = 'DELF/DALF';
            $provider = 'France Éducation international';
        } elseif (str_contains($hay, 'telc')) {
            $family = 'telc';
            $provider = 'telc gGmbH';
        }

        // Build candidates array if family was identified
        $candidates = [];
        if ($family) {
            $candidates[] = [
                'name' => $family,
                'family' => $family,
                'provider' => $provider,
                'score' => 0.70, // Moderate confidence from fallback heuristics
            ];
        }

        // Return result with whatever we could identify
        // We proceed even if some fields are missing
        $confidence = $family ? 0.75 : 0.50; // Lower confidence if no family identified
        $status = $family ? 'certain' : 'uncertain';

        return [
            'status' => $status,
            'confidence' => $confidence,
            'canonical' => [
                'family' => $family,
                'name' => $exam->title ?? null,
                'provider' => $provider,
                'variant' => null,
                'language_of_test' => $lang,
            ],
            'candidates' => $candidates,
            'followups' => [], // No followups - we continue with what we have
            'need_fields' => [], // No blocking on missing fields
            'anchors' => [],
        ];
    }

    /**
     * Build targeted questions for variant disambiguation
     */
    protected function buildVariantQuestions(?string $family, array $user, ?string $extracted): array
    {
        $questions = [];

        if (! $family) {
            return [
                ['q' => 'What is the exact name of the exam you are preparing for?', 'why' => 'Need to identify exam family'],
            ];
        }

        switch ($family) {
            case 'IELTS':
                $questions[] = [
                    'q' => 'Is this IELTS Academic or General Training?',
                    'why' => 'Academic has academic texts and data description tasks; General has practical texts and letters',
                ];
                break;

            case 'TOEFL iBT':
            case 'TOEFL':
                $questions[] = [
                    'q' => 'Is this TOEFL iBT (internet-based), TOEFL iBT Home Edition, or TOEFL Paper Edition?',
                    'why' => 'Format affects delivery mode, timing, and task types',
                ];
                break;

            case 'Goethe-Zertifikat':
                if (empty($user['target']['level'] ?? null)) {
                    $questions[] = [
                        'q' => 'Which level: A1, A2, B1, B2, C1, or C2?',
                        'why' => 'Each level has different task complexity, timing, and passing criteria',
                    ];
                }
                break;

            case 'DELF/DALF':
            case 'DELF':
            case 'DALF':
                if (empty($user['target']['level'] ?? null)) {
                    $questions[] = [
                        'q' => 'Which diploma: DELF A1, A2, B1, B2 or DALF C1, C2?',
                        'why' => 'Each level tests different competencies and has distinct formats',
                    ];
                }
                break;
        }

        return $questions;
    }

    /**
     * Extract document text from document ID
     */
    protected function extractDocumentText(string|int|null $docId): ?string
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
     * Get OpenAI file IDs for document if file attachments enabled.
     */
    protected function getDocumentFileIds(string|int|null $docId): array
    {
        if (! $docId || ! config('ai.file_attachments.enabled')) {
            return [];
        }

        $doc = ExamDocument::query()->find($docId);
        if (! $doc || ! $doc->openai_file_id || $doc->file_attachment_status !== 'uploaded') {
            return [];
        }

        return [$doc->openai_file_id];
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
     * Set confidence flags (hold, needs_confidence_boost)
     */
    protected function setConfidenceFlags(array $res): array
    {
        // Hold if: certain status + confidence >= 0.8 + no red_flags
        $shouldHold = (
            ($res['status'] ?? '') === 'certain'
            && ($res['confidence'] ?? 0) >= 0.8
            && empty($res['red_flags'] ?? [])
        );

        if ($shouldHold && ! isset($res['hold'])) {
            $res['hold'] = true;
        }

        // If confidence is between 0.70 and 0.8, we need additional verification
        $needsBoost = (
            ($res['status'] ?? '') === 'certain'
            && ($res['confidence'] ?? 0) >= 0.70
            && ($res['confidence'] ?? 0) < 0.8
            && empty($res['red_flags'] ?? [])
        );

        if ($needsBoost) {
            $res['needs_confidence_boost'] = true;
        }

        return $res;
    }

    /**
     * Log identity result
     */
    protected function logIdentityResult(
        Exam $exam,
        GenerationTask $task,
        array $user,
        string|int|null $docId,
        ?string $extracted,
        array $res,
        array $req,
        array $aiResponse
    ): void {
        GenerationLog::create([
            'exam_id' => $exam->id,
            'generation_task_id' => $task->id,
            'stage' => 'identity',
            'request' => [
                'user_input' => $user,
                'document_id' => $docId,
                'extracted_present' => ! is_null($extracted),
                'extracted_length' => $extracted ? mb_strlen($extracted) : 0,
                'messages' => $req['messages'],
            ],
            'response' => $res,
            'prompt_tokens' => $aiResponse['usage']['prompt_tokens'] ?? 0,
            'completion_tokens' => $aiResponse['usage']['completion_tokens'] ?? 0,
            'total_tokens' => $aiResponse['usage']['total_tokens'] ?? 0,
        ]);
    }

    /**
     * Log fallback result
     */
    protected function logFallbackResult(
        Exam $exam,
        GenerationTask $task,
        array $user,
        string|int|null $docId,
        string $error,
        array $res
    ): void {
        GenerationLog::create([
            'exam_id' => $exam->id,
            'generation_task_id' => $task->id,
            'stage' => 'identity_fallback',
            'request' => [
                'user_input' => $user,
                'document_id' => $docId,
                'error' => $error,
            ],
            'response' => $res,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ]);
    }
}
