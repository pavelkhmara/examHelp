<?php

namespace App\Services\LanguageApp;

use App\Models\ExamDocument;
use Illuminate\Support\Facades\Log;

/**
 * Document Structure Extractor
 *
 * Extracts structured data from exam documents to provide high-confidence
 * hints for the research pipeline.
 *
 * IMPORTANT: This service implements Task #1 from task_prompt_2_25_10_25.md
 * It's designed to be easily removable if needed - just remove calls to extract()
 * from the pipeline.
 *
 * Extracted data includes:
 * - Identity: exam name, provider, level, language
 * - Structure: sections, section duration, task count
 * - Archetypes: task details, duration, points, format
 * - Scoring: points distribution, thresholds
 */
class DocumentStructureExtractor extends AbstractAiService
{
    /**
     * Extract structured data from document
     *
     * @param ExamDocument $document
     * @return array|null Structured data or null if extraction fails
     */
    public function extract(ExamDocument $document): ?array
    {
        if (empty($document->extracted_text)) {
            Log::warning('DocumentStructureExtractor: No extracted text available', [
                'document_id' => $document->id,
            ]);
            return null;
        }

        try {
            $payload = $this->buildExtractionPayload($document->extracted_text);
            $response = $this->ai->generate($payload, [
                'json_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'identity' => [
                            'type' => 'object',
                            'properties' => [
                                'exam_name' => ['type' => ['string', 'null']],
                                'provider' => ['type' => ['string', 'null']],
                                'language' => ['type' => ['string', 'null']],
                                'level' => ['type' => ['string', 'null']],
                                'confidence' => ['type' => 'number', 'minimum' => 0.0, 'maximum' => 1.0],
                            ],
                            'required' => ['exam_name', 'provider', 'language', 'level', 'confidence'],
                            'additionalProperties' => false,
                        ],
                        'structure' => [
                            'type' => 'object',
                            'properties' => [
                                'sections' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'name' => ['type' => 'string'],
                                            'duration_minutes' => ['type' => ['integer', 'null']],
                                            'tasks' => [
                                                'type' => 'array',
                                                'items' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'type' => ['type' => 'string'],
                                                        'description' => ['type' => ['string', 'null']],
                                                    ],
                                                    'required' => ['type', 'description'],
                                                    'additionalProperties' => false,
                                                ],
                                            ],
                                        ],
                                        'required' => ['name', 'duration_minutes', 'tasks'],
                                        'additionalProperties' => false,
                                    ],
                                ],
                            ],
                            'required' => ['sections'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'required' => ['identity', 'structure'],
                    'additionalProperties' => false,
                ],
                'json_schema_name' => 'exam_structure',
            ]);

            if (!isset($response['content'])) {
                Log::warning('DocumentStructureExtractor: No content in AI response');
                return null;
            }

            $structured = json_decode($response['content'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('DocumentStructureExtractor: Invalid JSON response', [
                    'error' => json_last_error_msg(),
                ]);
                return null;
            }

            Log::info('DocumentStructureExtractor: Successfully extracted structure', [
                'document_id' => $document->id,
                'sections_found' => count($structured['structure']['sections'] ?? []),
                'identity_confidence' => $structured['identity']['confidence'] ?? 0,
            ]);

            return $structured;
        } catch (\Throwable $e) {
            Log::error('DocumentStructureExtractor: Extraction failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Build AI payload for structure extraction
     */
    protected function buildExtractionPayload(string $documentText): array
    {
        // Truncate if too long
        $maxChars = config('ai.docs.max_chars', 50000);
        if (strlen($documentText) > $maxChars) {
            $documentText = substr($documentText, 0, $maxChars) . "\n\n[...truncated...]";
        }

        $systemPrompt = <<<'PROMPT'
You are an expert at analyzing exam documents and extracting structured information.

Your task: Extract structured data from the provided exam document text.

EXTRACTION RULES:
1. Only extract information that is EXPLICITLY stated in the document
2. Do NOT invent or assume missing information
3. Mark confidence for identity fields (0.0 to 1.0)
4. Timing rules:
    4.1 Prefer section-level duration terms (e.g., "duration", "exam time", "total duration").
    4.2 Do NOT use per-task "working time" as the duration of the whole section.
    4.3 Only if a section-level duration is missing, you may SUM per-task working time
    inside the same section block (between headings like "Reading", "Listening", "Writing", "Speaking").
5. If multiple sources show different times, note all of them

OUTPUT FORMAT (JSON):
{
  "identity": {
    "exam_name": "string or null",
    "provider": "string or null",
    "level": "string (CEFR or other) or null",
    "language": "string or null",
    "confidence": 0.0-1.0
  },
  "structure": {
    "sections": [
      {
        "section_name": "string",
        "section_duration_minutes": number or null,
        "task_count": number or null,
        "category": "listening|reading|writing|speaking|other"
      }
    ],
    "total_duration_minutes": number or null
  },
  "archetypes": [
    {
      "task_name": "string",
      "task_type": "string",
      "section": "string",
      "duration_minutes": number or null,
      "points_available": number or null,
      "format": "string (multiple_choice, essay, etc.)",
      "instructions": "string or null",
      "word_count_target": "string or null"
    }
  ],
  "scoring": {
    "total_points": number or null,
    "passing_threshold": number or null,
    "scoring_type": "objective|subjective|mixed",
    "points_distribution": "string or null"
  },
  "extraction_notes": [
    "Any important notes or caveats about the extraction"
  ]
}

IMPORTANT:
- If a field is not explicitly stated in the document, use null
- Do NOT calculate or estimate values
- The data you extract will be used as high-confidence hints for further research
PROMPT;

        $userPrompt = <<<PROMPT
Extract structured data from this exam document:

--- DOCUMENT TEXT ---
{$documentText}
--- END DOCUMENT ---

Return ONLY valid JSON matching the specified format.
PROMPT;

        return [
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.3, // Low temperature for factual extraction
        ];
    }

    /**
     * Format extracted structure as hints for research pipeline
     *
     * Converts structured data into a format suitable for enhancing
     * the research overview AI prompt.
     */
    public function formatAsHints(array $structured): array
    {
        $hints = [];

        // Identity hints
        if (!empty($structured['identity'])) {
            $identity = $structured['identity'];
            $confidence = $identity['confidence'] ?? 0;

            if ($confidence >= 0.8) {
                $hints['exam_identity'] = [
                    'source' => 'document',
                    'confidence' => $confidence,
                    'exam_name' => $identity['exam_name'] ?? null,
                    'provider' => $identity['provider'] ?? null,
                    'level' => $identity['level'] ?? null,
                    'language' => $identity['language'] ?? null,
                ];
            }
        }

        // Structure hints
        if (!empty($structured['structure']['sections'])) {
            $hints['document_structure'] = [
                'source' => 'document',
                'sections' => $structured['structure']['sections'],
                'total_duration' => $structured['structure']['total_duration_minutes'] ?? null,
            ];
        }

        // Archetype hints
        if (!empty($structured['archetypes'])) {
            $hints['document_archetypes'] = [
                'source' => 'document',
                'tasks' => $structured['archetypes'],
            ];
        }

        // Scoring hints
        if (!empty($structured['scoring'])) {
            $hints['document_scoring'] = [
                'source' => 'document',
                'scoring' => $structured['scoring'],
            ];
        }

        // Notes
        if (!empty($structured['extraction_notes'])) {
            $hints['extraction_notes'] = $structured['extraction_notes'];
        }

        return $hints;
    }
}
