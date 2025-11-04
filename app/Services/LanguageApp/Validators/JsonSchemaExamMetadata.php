<?php

namespace App\Services\LanguageApp\Validators;

class JsonSchemaExamMetadata
{
    /**
     * Get JSON schema for metadata analysis response
     */
    public static function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'user_meta' => [
                    'type' => 'object',
                    'properties' => [
                        'user_language' => [
                            'type' => ['string', 'null'],
                            'description' => 'User\'s language (ISO 639-1 code)',
                        ],
                        'user_gender' => [
                            'type' => 'string',
                            'enum' => ['male', 'female', 'unknown'],
                            'description' => 'User\'s gender if inferable',
                        ],
                        'exam_language' => [
                            'type' => ['string', 'null'],
                            'description' => 'Target exam language (ISO 639-1 code)',
                        ],
                        'exam_level' => [
                            'type' => ['string', 'null'],
                            'enum' => ['A1', 'A2', 'B1', 'B2', 'C1', 'C2', null],
                            'description' => 'CEFR level mentioned by user',
                        ],
                        'target_score' => [
                            'type' => ['string', 'null'],
                            'description' => 'Desired score/band/result',
                        ],
                        'exam_purpose' => [
                            'type' => ['string', 'null'],
                            'description' => 'Why user needs this exam',
                        ],
                        'needs_certificate' => [
                            'type' => ['boolean', 'null'],
                            'description' => 'Does user need official certificate',
                        ],
                        'additional_info' => [
                            'type' => ['string', 'null'],
                            'description' => 'Other relevant information',
                        ],
                    ],
                    'required' => ['user_language', 'user_gender', 'exam_language'],
                    'additionalProperties' => false,
                ],
                'identity' => [
                    'type' => 'object',
                    'properties' => [
                        'exam_family' => [
                            'type' => ['string', 'null'],
                            'description' => 'Official exam family name',
                        ],
                        'exam_name' => [
                            'type' => ['string', 'null'],
                            'description' => 'Full official exam name',
                        ],
                        'exam_code' => [
                            'type' => ['string', 'null'],
                            'description' => 'Official exam code',
                        ],
                        'provider' => [
                            'type' => ['string', 'null'],
                            'description' => 'Exam provider organization',
                        ],
                        'country' => [
                            'type' => ['string', 'null'],
                            'description' => 'Country of exam or relevance',
                        ],
                        'exam_language' => [
                            'type' => ['string', 'null'],
                            'description' => 'Language of the exam (ISO 639-1)',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'confidence' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'maximum' => 1,
                    'description' => 'Confidence level of the analysis',
                ],
                'reasoning' => [
                    'type' => 'string',
                    'description' => 'Brief explanation of analysis',
                ],
            ],
            'required' => ['user_meta', 'identity', 'confidence'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Validate AI response against schema
     *
     * @param array $response AI response to validate
     * @return array Validated response
     * @throws \RuntimeException if validation fails
     */
    public function validate(array $response): array
    {
        // Basic structure validation
        if (!isset($response['user_meta']) || !is_array($response['user_meta'])) {
            throw new \RuntimeException('Response missing required field: user_meta');
        }

        if (!isset($response['identity']) || !is_array($response['identity'])) {
            throw new \RuntimeException('Response missing required field: identity');
        }

        if (!isset($response['confidence']) || !is_numeric($response['confidence'])) {
            throw new \RuntimeException('Response missing required field: confidence');
        }

        // Validate confidence range
        $confidence = (float) $response['confidence'];
        if ($confidence < 0 || $confidence > 1) {
            throw new \RuntimeException('Confidence must be between 0 and 1');
        }

        // Validate user_meta required fields
        $userMeta = $response['user_meta'];
        if (!isset($userMeta['user_gender']) || !in_array($userMeta['user_gender'], ['male', 'female', 'unknown'])) {
            throw new \RuntimeException('user_meta.user_gender must be one of: male, female, unknown');
        }

        // Validate exam_level if provided
        if (isset($userMeta['exam_level']) && $userMeta['exam_level'] !== null) {
            $validLevels = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'];
            if (!in_array($userMeta['exam_level'], $validLevels)) {
                throw new \RuntimeException('user_meta.exam_level must be one of: ' . implode(', ', $validLevels));
            }
        }

        return $response;
    }
}
