<?php

namespace App\Services\LanguageApp\Validators;

use Illuminate\Validation\ValidationException;

/**
 * QuestionGroupValidator - validates question group structure
 *
 * Validates:
 * - Basic structure (id, title, stimulus)
 * - Playback settings (max_plays, enforcement)
 * - Questions array (minimum 2 items if present)
 *
 * Based on: docs/generation_stage/question_group_schema.json
 */
final class QuestionGroupValidator
{
    /**
     * Get JSON schema for question group validation
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['id', 'title', 'stimulus'],
            'properties' => [
                'id' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'Unique identifier for question group',
                ],
                'title' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'Display title',
                ],
                'instructions' => [
                    'type' => 'object',
                    'properties' => [
                        'brief' => ['type' => 'string'],
                        'full' => ['type' => 'string'],
                        'audio_url' => ['type' => 'string'],
                    ],
                ],
                'stimulus' => [
                    'type' => 'object',
                    'properties' => [
                        'text_html' => ['type' => 'string'],
                        'images' => [
                            'type' => 'array',
                            'items' => [
                                'oneOf' => [
                                    ['type' => 'string'],
                                    [
                                        'type' => 'object',
                                        'required' => ['url'],
                                        'properties' => [
                                            'url' => ['type' => 'string'],
                                            'alt' => ['type' => 'string'],
                                            'caption' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'audio' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'video' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'resources' => ['type' => 'object'],
                    ],
                ],
                'playback_settings' => [
                    'oneOf' => [
                        ['type' => 'object', 'properties' => [
                            'max_plays' => [
                                'oneOf' => [
                                    ['type' => 'integer', 'minimum' => 1],
                                    ['type' => 'null'],
                                ],
                                'description' => 'Max playback count (null = unlimited)',
                            ],
                            'enforcement' => [
                                'type' => 'string',
                                'enum' => ['strict', 'advisory', 'none'],
                                'description' => 'Enforcement mode',
                            ],
                            'reset_on_navigation' => [
                                'type' => 'boolean',
                                'description' => 'Reset play counter on navigation',
                            ],
                            'show_counter' => [
                                'type' => 'boolean',
                                'description' => 'Display remaining plays counter',
                            ],
                        ]],
                        ['type' => 'null'],
                    ],
                    'description' => 'Playback settings (null for non-audio groups like reading)',
                ],
                'questions' => [
                    'type' => 'array',
                    'minItems' => 2,
                    'maxItems' => 20,
                    'description' => 'Questions in this group (minimum 2)',
                ],
                'metadata' => [
                    'type' => 'object',
                    'properties' => [
                        'total_points' => ['type' => 'number', 'minimum' => 0],
                        'suggested_time_sec' => ['type' => 'integer', 'minimum' => 0],
                        'duration_sec' => ['type' => 'integer', 'minimum' => 0],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                'order' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => 'Display order within section',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Validate question group data
     *
     * @param  array  $data  Question group data to validate
     * @return array Validated data
     *
     * @throws ValidationException
     */
    public function validate(array $data): array
    {
        // Basic schema validation
        AiJsonValidator::validate(self::schema(), $data);

        // Additional business rules
        $this->validateBusinessRules($data);

        return $data;
    }

    /**
     * Validate business rules beyond JSON schema
     *
     * @throws ValidationException
     */
    protected function validateBusinessRules(array $data): void
    {
        $errors = [];

        // Rule 1: Check playback_settings if present
        if (isset($data['playback_settings'])) {
            $settings = $data['playback_settings'];

            // max_plays must be positive integer or null
            if (array_key_exists('max_plays', $settings)) {
                $maxPlays = $settings['max_plays'];
                if (! is_null($maxPlays) && (! is_int($maxPlays) || $maxPlays < 1)) {
                    $errors['playback_settings.max_plays'] = 'max_plays must be a positive integer or null';
                }
            }

            // enforcement must be valid enum value
            if (isset($settings['enforcement'])) {
                $enforcement = $settings['enforcement'];
                if (! in_array($enforcement, ['strict', 'advisory', 'none'], true)) {
                    $errors['playback_settings.enforcement'] = 'enforcement must be one of: strict, advisory, none';
                }
            }
        }

        // Rule 2: Validate questions array if present
        if (isset($data['questions'])) {
            if (! is_array($data['questions'])) {
                $errors['questions'] = 'questions must be an array';
            } elseif (count($data['questions']) < 2) {
                $errors['questions'] = 'question group must have at least 2 questions';
            } elseif (count($data['questions']) > 20) {
                $errors['questions'] = 'question group cannot have more than 20 questions';
            }
        }

        // Rule 3: Validate stimulus is not empty
        if (isset($data['stimulus']) && is_array($data['stimulus'])) {
            $hasContent = false;
            foreach (['text_html', 'images', 'audio', 'video'] as $key) {
                if (! empty($data['stimulus'][$key])) {
                    $hasContent = true;
                    break;
                }
            }

            if (! $hasContent) {
                $errors['stimulus'] = 'stimulus must contain at least one of: text_html, images, audio, video';
            }
        }

        // Rule 4: Validate order is non-negative
        if (isset($data['order'])) {
            if (! is_int($data['order']) || $data['order'] < 0) {
                $errors['order'] = 'order must be a non-negative integer';
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Validate only the structure without business rules (softer validation)
     *
     * @throws ValidationException
     */
    public function validateStructure(array $data): array
    {
        AiJsonValidator::validate(self::schema(), $data);

        return $data;
    }
}
