<?php

namespace App\Services\LanguageApp\Validators;

use Illuminate\Validation\ValidationException;

/**
 * JsonSchemaExamExamples  20;840F8O ?@8<5@>2 7040=89 >B AI.
 *
 * 68405<0O AB@C:BC@0 2E>4=KE 40==KE:
 * {
 *   "examples": [
 *     {
 *       "archetype_id": "string",           // ID 0@E5B8?0 87 AB@C:BC@K
 *       "question": "string",               // "5:AB 7040=8O
 *       "description": "string|null",       // ?8A0=85 7040=8O
 *       "duration_minutes": int|null,       // B2545==>5 2@5<O
 *       "instructions": "string|null",      // =AB@C:F88 ?> 2K?>;=5=8N
 *       "example_response": object|null,    // @8<5@ 2K?>;=5=8O
 *       "assessment_guide": "string|null",  // ><<5=B0@88 ?> >F5=:5
 *       "type": "string",                   // "8? 2>?@>A0 (QuestionType enum)
 *       "payload": object|null              // 0==K5 4;O B8?0 2>?@>A0
 *     },
 *     ...
 *   ]
 * }
 */
final class JsonSchemaExamExamples
{
    public function validate(mixed $data): array
    {
        if (! is_array($data) || ! $this->isAssoc($data)) {
            throw ValidationException::withMessages(['root' => 'Response must be a JSON object']);
        }

        if (! isset($data['examples']) || ! is_array($data['examples'])) {
            throw ValidationException::withMessages(['examples' => 'examples must be an array']);
        }

        $normalized = [];

        foreach ($data['examples'] as $i => $example) {
            if (! is_array($example) || ! $this->isAssoc($example)) {
                throw ValidationException::withMessages(["examples.$i" => 'must be an object']);
            }

            // Required fields
            $archetypeId = $this->mustString($example, 'archetype_id', "examples.$i.archetype_id");
            $question = $this->mustString($example, 'question', "examples.$i.question");
            $type = $this->mustString($example, 'type', "examples.$i.type");

            // Optional fields with defaults
            $description = $this->optString($example, 'description', null);
            $durationMinutes = $this->optInt($example, 'duration_minutes', null);
            $instructions = $this->optString($example, 'instructions', null);
            $exampleResponse = $this->optArray($example, 'example_response', null);
            $assessmentGuide = $this->optString($example, 'assessment_guide', null);
            $payload = $this->optArray($example, 'payload', null);

            $normalized[] = [
                'archetype_id' => $archetypeId,
                'question' => $question,
                'description' => $description,
                'duration_minutes' => $durationMinutes,
                'instructions' => $instructions,
                'example_response' => $exampleResponse,
                'assessment_guide' => $assessmentGuide,
                'type' => $type,
                'payload' => $payload,
            ];
        }

        return ['examples' => $normalized];
    }

    private function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    private function mustString(array $data, string $key, ?string $path = null): string
    {
        if (! array_key_exists($key, $data)) {
            throw ValidationException::withMessages([($path ?? $key) => "$key is required"]);
        }
        if (! is_string($data[$key]) || trim($data[$key]) === '') {
            throw ValidationException::withMessages([($path ?? $key) => "$key must be a non-empty string"]);
        }

        return trim($data[$key]);
    }

    private function optString(array $data, string $key, ?string $default = null): ?string
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return $default;
        }
        if (! is_string($data[$key])) {
            return $default;
        }

        return trim($data[$key]) !== '' ? trim($data[$key]) : $default;
    }

    private function optInt(array $data, string $key, ?int $default = null): ?int
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return $default;
        }
        if (is_int($data[$key])) {
            return $data[$key];
        }
        if (is_numeric($data[$key])) {
            return (int) $data[$key];
        }

        return $default;
    }

    private function optArray(array $data, string $key, ?array $default = null): ?array
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return $default;
        }
        if (! is_array($data[$key])) {
            return $default;
        }

        return $data[$key];
    }
}
