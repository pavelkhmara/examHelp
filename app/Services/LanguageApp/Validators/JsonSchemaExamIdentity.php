<?php

namespace App\Services\LanguageApp\Validators;

use Illuminate\Validation\ValidationException;

final class JsonSchemaExamIdentity
{
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['status', 'confidence', 'canonical', 'candidates', 'followups', 'need_fields', 'anchors'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['certain', 'uncertain']],
                'confidence' => ['type' => 'number', 'minimum' => 0.0, 'maximum' => 1.0],
                'canonical' => [
                    'type' => 'object',
                    'required' => ['family', 'name', 'provider', 'variant', 'language_of_test'],
                    'properties' => [
                        'family' => ['type' => ['string', 'null']],
                        'name' => ['type' => ['string', 'null']],
                        'provider' => ['type' => ['string', 'null']],
                        'variant' => ['type' => ['string', 'null']],
                        'language_of_test' => ['type' => ['string', 'null']],
                    ],
                    'additionalProperties' => false,
                ],
                'candidates' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['family', 'name', 'provider', 'score'],
                        'properties' => [
                            'family' => ['type' => ['string', 'null']],
                            'name' => ['type' => ['string', 'null']],
                            'provider' => ['type' => ['string', 'null']],
                            'score' => ['type' => 'number', 'minimum' => 0.0, 'maximum' => 1.0],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'followups' => ['type' => 'array', 'items' => ['type' => 'string']],
                'need_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
                'anchors' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['page', 'snippet'],
                        'properties' => [
                            'page' => ['type' => ['integer', 'null']],
                            'snippet' => ['type' => 'string'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],

                'hold' => ['type' => ['boolean', 'null']],
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
