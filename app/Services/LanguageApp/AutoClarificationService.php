<?php

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\ExamDocument;
use App\Models\GenerationTask;
use App\Services\LanguageApp\Prompts\PromptAutoClarification;

/**
 * Service for auto-clarifying exam identity without user input
 *
 * Responsibilities:
 * - Use AI to infer missing exam details when user confirmation not available
 * - Return inferred data with confidence and disclaimer
 */
class AutoClarificationService extends AbstractAiService
{
    /**
     * Run AI auto-clarification when user confirmation is not available
     */
    public function runAutoClarification(Exam $exam, GenerationTask $task, array $identityResult): array
    {
        $userInput = $task->request['user_input'] ?? [];
        $documentId = $task->request['document_id'] ?? null;

        // Get extracted text if document exists
        $extractedText = $this->extractDocumentText($documentId);

        // Build prompt
        $prompt = PromptAutoClarification::build(
            $identityResult,
            $userInput,
            $extractedText
        );

        // Call AI
        $payload = [
            'exam_slug' => $exam->slug,
            'stage' => 'auto_clarification',
            'prompt' => $prompt,
        ];

        $res = $this->callAi($payload, ['web' => false]);
        $this->log($task, 'auto_clarification', $payload, $res);

        // Parse response
        $clarification = $res['content'] ?? [];

        return [
            'selected_candidate' => $clarification['selected_candidate'] ?? null,
            'inferred_data' => $clarification['inferred_data'] ?? [],
            'confidence' => $clarification['confidence'] ?? 0.5,
            'reasoning' => $clarification['reasoning'] ?? 'AI made best-effort inference from available data',
            'disclaimer' => $clarification['disclaimer'] ?? 'AI-inferred without user confirmation',
        ];
    }

    /**
     * Extract document text from document ID
     */
    protected function extractDocumentText(int|string|null $documentId): ?string
    {
        if (! $documentId) {
            return null;
        }

        $doc = ExamDocument::find($documentId);
        if (! $doc || $doc->status !== 'completed') {
            return null;
        }

        return $doc->extracted_text;
    }
}
