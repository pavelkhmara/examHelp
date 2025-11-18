<?php

namespace App\Services\LanguageApp\Validators;

use Illuminate\Validation\ValidationException;

final class JsonSchemaExamIdentity
{
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['status', 'confidence', 'canonical', 'candidates', 'followups', 'need_fields', 'anchors', 'hold'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['certain', 'uncertain']],
                'confidence' => ['type' => 'number', 'minimum' => 0.0, 'maximum' => 1.0],
                'canonical' => [
                    'type' => 'object',
                    'properties' => [
                        'family' => ['type' => ['string', 'null'], 'description' => 'Exam family (e.g., IELTS, TOEFL)'],
                        'name' => ['type' => ['string', 'null'], 'description' => 'Full exam name'],
                        'provider' => ['type' => ['string', 'null'], 'description' => 'Exam provider organization'],
                        'variant' => ['type' => ['string', 'null'], 'description' => 'Exam variant if applicable'],
                        'language_of_test' => ['type' => ['string', 'null'], 'description' => 'Language being tested'],
                    ],
                    'required' => ['family', 'name', 'provider', 'variant', 'language_of_test'],
                    'additionalProperties' => false,
                ],
                'candidates' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'family' => ['type' => ['string', 'null']],
                            'name' => ['type' => ['string', 'null']],
                            'provider' => ['type' => ['string', 'null']],
                            'score' => ['type' => 'number', 'minimum' => 0.0, 'maximum' => 1.0],
                        ],
                        'required' => ['family', 'name', 'provider', 'score'],
                        'additionalProperties' => false,
                    ],
                ],
                'followups' => ['type' => 'array', 'items' => ['type' => 'string']],
                'need_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
                'anchors' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'page' => ['type' => ['integer', 'null'], 'description' => 'Page number where evidence was found'],
                            'snippet' => ['type' => 'string', 'description' => 'Text snippet from document'],
                        ],
                        'required' => ['page', 'snippet'],
                        'additionalProperties' => false,
                    ],
                ],

                'hold' => ['type' => ['boolean', 'null'], 'description' => 'Whether to pause processing'],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Строго валидирует $data по локальной схеме.
     * Возвращает исходные данные, если валидация прошла (или кидает ValidationException).
     *
     * @throws ValidationException
     */
    public function validate(array $data): array
    {
        // ВАЖНО: AiJsonValidator::validate($schema, $data) — статический метод, возвращает void. :contentReference[oaicite:1]{index=1}
        \App\Services\LanguageApp\Validators\AiJsonValidator::validate(self::schema(), $data);

        return $data;
    }
}
