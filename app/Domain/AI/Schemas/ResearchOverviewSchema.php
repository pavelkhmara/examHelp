<?php

namespace App\Domain\AI\Schemas;

use App\Domain\Taxonomy\QuestionType;

/**
 * Strict JSON Schema for Research → Overview (archetypes → typed tasks)
 * Types come from whitelist enum QuestionType.
 */
final class ResearchOverviewSchema
{
    public static function make(): array
    {
        // Derive whitelist from enum
        $allowed = method_exists(QuestionType::class, 'all')
            ? QuestionType::all()
            : array_map(fn($c) => $c->value, QuestionType::cases());

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['tasks', 'sources', 'summary'],
            'properties' => [
                'summary' => ['type' => 'string', 'minLength' => 1],
                'tasks' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['type', 'name', 'rationale', 'expected_payload'],
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => $allowed], // strict whitelist
                            'name' => ['type' => 'string', 'minLength' => 2],
                            'rationale' => ['type' => 'string'],
                            'expected_payload' => ['type' => 'object'], // произвольная форма под тип
                            'step_order' => ['type' => 'integer', 'minimum' => 1],
                            'category_key' => ['type' => 'string'],
                        ],
                    ],
                ],
                'sources' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['url', 'title'],
                        'properties' => [
                            'url' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'publisher' => ['type' => 'string'],
                            'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                            'type' => ['type' => 'string'], // web|user_doc
                        ],
                    ],
                ],
            ],
        ];
    }
}
