<?php

namespace App\Services\LanguageApp\Schemas;

/**
 * OpenAI Structured Outputs JSON Schema for Question Generation
 *
 * Wraps array of questions in object: {"questions": [...]}
 * This is required because OpenAI json_object format cannot return top-level arrays.
 *
 * Structured Outputs provides 100% guarantee that response matches this schema.
 */
final class QuestionArraySchema
{
    /**
     * Get JSON Schema for OpenAI Structured Outputs
     *
     * @param  string  $questionType  Type of question (single_select, writing_prompt, etc.)
     * @return array OpenAI-compatible JSON Schema
     */
    public static function getSchema(string $questionType): array
    {
        $questionSchema = self::getQuestionSchema($questionType);

        return [
            'type' => 'object',
            'properties' => [
                'questions' => [
                    'type' => 'array',
                    'description' => 'Array of generated exam questions',
                    'items' => $questionSchema,
                ],
            ],
            'required' => ['questions'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Get recommended response_type for question type based on mapping
     */
    private static function getResponseType(string $questionType): string
    {
        $mapping = [
            'single_select' => 'selection',
            'multi_select' => 'selection',
            'true_false' => 'selection',
            'yes_no_ng' => 'selection',
            'dropdown_cloze' => 'selection',
            'gap_cloze' => 'text',
            'banked_cloze' => 'selection',
            'matching' => 'matching',
            'order_sentences' => 'ordering',
            'order_words' => 'ordering',
            'highlight_text' => 'spans',
            'short_answer' => 'text',
            'numeric' => 'numeric',
            'dictation' => 'text',
            'writing_prompt' => 'text',
            'speaking_prompt' => 'audio',
            'listen_mcq' => 'selection',
            'translation' => 'text',
            'roleplay' => 'audio',
            'note_completion' => 'text',
        ];

        return $mapping[$questionType] ?? 'text';
    }

    /**
     * Get recommended response mode for question type
     */
    private static function getResponseMode(string $questionType): string
    {
        return self::getResponseType($questionType); // Same as response_type for simplicity
    }

    /**
     * Get schema for a single question (V2 format)
     */
    private static function getQuestionSchema(string $questionType): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'string',
                    'description' => 'Unique identifier for the question',
                ],
                'version' => [
                    'type' => 'string',
                    'description' => 'Schema version (e.g., "2.0")',
                ],
                'type' => [
                    'type' => 'string',
                    'description' => 'Question type',
                    'enum' => self::getValidTypes(),
                ],
                'skills_measured' => [
                    'type' => 'array',
                    'description' => 'Skills being tested',
                    'items' => ['type' => 'string'],
                    'minItems' => 1,
                ],
                'time_limit_sec' => [
                    'type' => 'integer',
                    'description' => 'Time limit in seconds',
                    'minimum' => 10,
                ],
                'instructions' => [
                    'type' => 'object',
                    'description' => 'Instructions for the question',
                    'properties' => [
                        'brief' => ['type' => 'string'],
                        'full' => ['type' => 'string'],
                    ],
                    'required' => ['brief', 'full'],
                    'additionalProperties' => false,
                ],
                'stimulus' => [
                    'type' => 'object',
                    'description' => 'Question stimulus (text, images, audio, video)',
                    'properties' => [
                        'text_html' => ['type' => 'string'],
                    ],
                    'required' => ['text_html'],
                    'additionalProperties' => false,
                ],
                'interaction' => [
                    'type' => 'object',
                    'description' => 'How the user interacts with the question',
                    'properties' => [
                        'response_type' => [
                            'type' => 'string',
                            'enum' => [self::getResponseType($questionType)],
                            'description' => 'Response interaction type (fixed for this question type)',
                        ],
                        'options' => [
                            'type' => 'array',
                            'description' => 'Answer options for selection-based questions',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'string'],
                                    'text' => ['type' => 'string'],
                                ],
                                'required' => ['id', 'text'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['response_type', 'options'],
                    'additionalProperties' => false,
                ],
                'response' => [
                    'type' => 'object',
                    'description' => 'Expected response format',
                    'properties' => [
                        'mode' => [
                            'type' => 'string',
                            'enum' => [self::getResponseMode($questionType)],
                            'description' => 'Response mode (fixed for this question type)',
                        ],
                    ],
                    'required' => ['mode'],
                    'additionalProperties' => false,
                ],
                'scoring' => [
                    'type' => 'object',
                    'description' => 'Scoring configuration',
                    'properties' => [
                        'method' => [
                            'type' => 'string',
                            'enum' => ['keyed', 'partial', 'rubric', 'hybrid'],
                        ],
                        'answer_key' => [
                            'type' => 'array',
                            'description' => 'Correct answer(s) - option IDs for keyed scoring',
                            'items' => ['type' => 'string'],
                        ],
                        'max_score' => [
                            'type' => 'number',
                            'description' => 'Maximum score for this question',
                        ],
                    ],
                    'required' => ['method', 'answer_key', 'max_score'],
                    'additionalProperties' => false,
                ],
                'metadata' => [
                    'type' => 'object',
                    'description' => 'Additional metadata',
                    'properties' => [
                        'cefr' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                                'enum' => ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'],
                            ],
                            'minItems' => 1,
                        ],
                        'difficulty' => [
                            'type' => 'string',
                            'enum' => ['easy', 'medium', 'hard'],
                        ],
                        'topic' => ['type' => 'string'],
                        'language' => ['type' => 'string'],
                    ],
                    'required' => ['cefr', 'difficulty', 'topic', 'language'],
                    'additionalProperties' => false,
                ],
            ],
            'required' => [
                'id',
                'version',
                'type',
                'skills_measured',
                'time_limit_sec',
                'instructions',
                'stimulus',
                'interaction',
                'response',
                'scoring',
                'metadata',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Get list of valid question types
     */
    private static function getValidTypes(): array
    {
        return [
            'single_select', 'multi_select', 'true_false', 'yes_no_ng',
            'dropdown_cloze', 'gap_cloze', 'banked_cloze',
            'matching', 'order_sentences', 'order_words', 'highlight_text',
            'short_answer', 'numeric', 'dictation',
            'writing_prompt', 'speaking_prompt',
            'listen_mcq',
            'translation', 'roleplay', 'note_completion',
        ];
    }
}
