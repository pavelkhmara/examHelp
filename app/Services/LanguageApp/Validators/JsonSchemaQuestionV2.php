<?php

namespace App\Services\LanguageApp\Validators;

use Illuminate\Validation\ValidationException;

/**
 * JsonSchemaQuestionV2 - Validator for v2 Question Archetype
 *
 * Validates question structure according to s2_exam_question_archetype_v2.json
 *
 * Required fields:
 * - id, type, skills_measured, time_limit_sec
 * - instructions, stimulus, interaction, response, scoring, metadata
 *
 * Validates logical consistency:
 * - interaction.response_type ↔ response.mode
 * - scoring.method ↔ answer_key/rubric
 * - response.mode=audio requires recording_limit_sec
 */
final class JsonSchemaQuestionV2
{
    private const VALID_TYPES = [
        'single_select', 'multi_select', 'true_false', 'yes_no_ng',
        'dropdown_cloze', 'gap_cloze', 'banked_cloze',
        'matching', 'order_sentences', 'order_words', 'highlight_text',
        'short_answer', 'numeric', 'dictation',
        'writing_prompt', 'speaking_prompt',
        'listen_mcq', 'video_mcq',
        'translation', 'roleplay', 'note_completion',
    ];

    private const VALID_SCORING_METHODS = ['keyed', 'partial', 'rubric', 'hybrid'];

    private const VALID_RESPONSE_MODES = ['text', 'audio', 'numeric', 'selection', 'ordering', 'matching', 'spans'];

    private const VALID_DIFFICULTIES = ['easy', 'medium', 'hard'];

    private const VALID_CEFR_LEVELS = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'];

    public function validate(mixed $data): array
    {
        if (! is_array($data) || ! $this->isAssoc($data)) {
            throw ValidationException::withMessages(['root' => 'Question must be a JSON object']);
        }

        // Required fields
        $id = $this->mustString($data, 'id');
        $type = $this->validateType($data['type'] ?? null);
        $skillsMeasured = $this->validateSkillsMeasured($data['skills_measured'] ?? null);
        $timeLimitSec = $this->mustInteger($data, 'time_limit_sec', 10);
        $instructions = $this->validateInstructions($data['instructions'] ?? null);
        $stimulus = $this->validateStimulus($data['stimulus'] ?? null);
        $interaction = $this->validateInteraction($data['interaction'] ?? null);
        $response = $this->validateResponse($data['response'] ?? null);
        $scoring = $this->validateScoring($data['scoring'] ?? null);
        $metadata = $this->validateMetadata($data['metadata'] ?? null);

        // Optional fields
        $version = $data['version'] ?? '2.0';
        $ioSignature = $data['io_signature'] ?? null;
        $constraints = $data['constraints'] ?? null;
        $randomization = $data['randomization'] ?? null;
        $outcomeReporting = $data['outcome_reporting'] ?? null;
        $typicalErrors = $data['typical_errors'] ?? null;
        $uiHints = $data['ui_hints'] ?? null;
        $accessibility = $data['accessibility'] ?? null;

        // Logical checks
        $this->validateInteractionConsistency($type, $interaction, $response);
        $this->validateScoringConsistency($scoring, $interaction);

        return [
            'id' => $id,
            'version' => $version,
            'type' => $type,
            'skills_measured' => $skillsMeasured,
            'time_limit_sec' => $timeLimitSec,
            'instructions' => $instructions,
            'stimulus' => $stimulus,
            'interaction' => $interaction,
            'response' => $response,
            'scoring' => $scoring,
            'metadata' => $metadata,
            'io_signature' => $ioSignature,
            'constraints' => $constraints,
            'randomization' => $randomization,
            'outcome_reporting' => $outcomeReporting,
            'typical_errors' => $typicalErrors,
            'ui_hints' => $uiHints,
            'accessibility' => $accessibility,
        ];
    }

    private function validateType(?string $type): string
    {
        if (! $type || ! is_string($type)) {
            throw ValidationException::withMessages(['type' => 'type is required and must be a string']);
        }

        if (! in_array($type, self::VALID_TYPES)) {
            throw ValidationException::withMessages([
                'type' => 'Invalid type. Must be one of: '.implode(', ', self::VALID_TYPES),
            ]);
        }

        return $type;
    }

    private function validateSkillsMeasured(?array $skills): array
    {
        if (! is_array($skills) || empty($skills)) {
            throw ValidationException::withMessages(['skills_measured' => 'skills_measured is required and must be a non-empty array']);
        }

        foreach ($skills as $skill) {
            if (! is_string($skill)) {
                throw ValidationException::withMessages(['skills_measured' => 'Each skill must be a string']);
            }
        }

        return $skills;
    }

    private function validateInstructions(?array $instructions): array
    {
        if (! is_array($instructions)) {
            throw ValidationException::withMessages(['instructions' => 'instructions is required and must be an object']);
        }

        $brief = $this->mustString($instructions, 'brief');
        $full = $this->mustString($instructions, 'full');

        return [
            'brief' => $brief,
            'full' => $full,
            'audio_url' => $instructions['audio_url'] ?? null,
            'video_url' => $instructions['video_url'] ?? null,
            'l10n' => $instructions['l10n'] ?? null,
        ];
    }

    private function validateStimulus(?array $stimulus): array
    {
        if (! is_array($stimulus)) {
            throw ValidationException::withMessages(['stimulus' => 'stimulus is required and must be an object']);
        }

        // At least one stimulus type must be present
        $hasStimulus = ! empty($stimulus['text_html'])
            || ! empty($stimulus['images'])
            || ! empty($stimulus['audio'])
            || ! empty($stimulus['video']);

        if (! $hasStimulus) {
            throw ValidationException::withMessages(['stimulus' => 'stimulus must have at least one content type (text_html, images, audio, or video)']);
        }

        return [
            'text_html' => $stimulus['text_html'] ?? null,
            'images' => $stimulus['images'] ?? null,
            'audio' => $stimulus['audio'] ?? null,
            'video' => $stimulus['video'] ?? null,
            'resources' => $stimulus['resources'] ?? null,
            'media_metadata' => $stimulus['media_metadata'] ?? null,
        ];
    }

    private function validateInteraction(?array $interaction): array
    {
        if (! is_array($interaction)) {
            throw ValidationException::withMessages(['interaction' => 'interaction is required and must be an object']);
        }

        $responseType = $this->mustString($interaction, 'response_type');

        return [
            'response_type' => $responseType,
            'options' => $interaction['options'] ?? null,
            'pairs' => $interaction['pairs'] ?? null,
            'bank' => $interaction['bank'] ?? null,
            'spans' => $interaction['spans'] ?? null,
        ];
    }

    private function validateResponse(?array $response): array
    {
        if (! is_array($response)) {
            throw ValidationException::withMessages(['response' => 'response is required and must be an object']);
        }

        $mode = $response['mode'] ?? null;
        if (! $mode || ! is_string($mode)) {
            throw ValidationException::withMessages(['response.mode' => 'response.mode is required']);
        }

        if (! in_array($mode, self::VALID_RESPONSE_MODES)) {
            throw ValidationException::withMessages([
                'response.mode' => 'Invalid response mode. Must be one of: '.implode(', ', self::VALID_RESPONSE_MODES),
            ]);
        }

        return [
            'mode' => $mode,
            'max_words' => $response['max_words'] ?? null,
            'recording_limit_sec' => $response['recording_limit_sec'] ?? null,
            'attempts' => $response['attempts'] ?? null,
            'validation' => $response['validation'] ?? null,
        ];
    }

    private function validateScoring(?array $scoring): array
    {
        if (! is_array($scoring)) {
            throw ValidationException::withMessages(['scoring' => 'scoring is required and must be an object']);
        }

        $method = $this->mustString($scoring, 'method');

        if (! in_array($method, self::VALID_SCORING_METHODS)) {
            throw ValidationException::withMessages([
                'scoring.method' => 'Invalid scoring method. Must be one of: '.implode(', ', self::VALID_SCORING_METHODS),
            ]);
        }

        return [
            'method' => $method,
            'answer_key' => $scoring['answer_key'] ?? null,
            'partial_rules' => $scoring['partial_rules'] ?? null,
            'rubric' => $scoring['rubric'] ?? null,
            'max_score' => $scoring['max_score'] ?? null,
        ];
    }

    private function validateMetadata(?array $metadata): array
    {
        if (! is_array($metadata)) {
            throw ValidationException::withMessages(['metadata' => 'metadata is required and must be an object']);
        }

        // Required fields in metadata
        if (! isset($metadata['cefr']) || ! is_array($metadata['cefr']) || empty($metadata['cefr'])) {
            throw ValidationException::withMessages(['metadata.cefr' => 'metadata.cefr is required and must be a non-empty array']);
        }

        // Validate CEFR levels
        foreach ($metadata['cefr'] as $level) {
            if (! in_array($level, self::VALID_CEFR_LEVELS)) {
                throw ValidationException::withMessages(['metadata.cefr' => "Invalid CEFR level: $level"]);
            }
        }

        $difficulty = $this->mustString($metadata, 'difficulty');
        if (! in_array($difficulty, self::VALID_DIFFICULTIES)) {
            throw ValidationException::withMessages([
                'metadata.difficulty' => 'Invalid difficulty. Must be one of: '.implode(', ', self::VALID_DIFFICULTIES),
            ]);
        }

        $topic = $this->mustString($metadata, 'topic');
        $language = $this->mustString($metadata, 'language');

        return [
            'cefr' => $metadata['cefr'],
            'difficulty' => $difficulty,
            'topic' => $topic,
            'language' => $language,
            'sources' => $metadata['sources'] ?? null,
            'tags' => $metadata['tags'] ?? null,
        ];
    }

    private function validateInteractionConsistency(string $type, array $interaction, array $response): void
    {
        // Load recommended defaults for this type
        $mappingPath = base_path('docs/generation_stage/s2_type_pattern_mapping.json');
        if (! file_exists($mappingPath)) {
            // Skip consistency check if mapping file not found
            return;
        }

        $mapping = json_decode(file_get_contents($mappingPath), true);
        $defaults = $mapping['type_defaults'][$type]['recommended'] ?? null;

        if (! $defaults) {
            // No strict requirements for this type
            return;
        }

        // Check interaction.response_type
        if (isset($defaults['interaction']['response_type'])) {
            $expected = $defaults['interaction']['response_type'];
            $actual = $interaction['response_type'] ?? null;

            if ($actual !== $expected) {
                throw ValidationException::withMessages([
                    'interaction.response_type' => "Type '$type' expects interaction.response_type='$expected', got '$actual'",
                ]);
            }
        }

        // Check response.mode
        if (isset($defaults['response']['mode'])) {
            $expected = $defaults['response']['mode'];
            $actual = $response['mode'] ?? null;

            if ($actual !== $expected) {
                throw ValidationException::withMessages([
                    'response.mode' => "Type '$type' expects response.mode='$expected', got '$actual'",
                ]);
            }
        }

        // Check recording_limit_sec for audio response
        if (($response['mode'] ?? '') === 'audio') {
            if (! isset($response['recording_limit_sec']) || $response['recording_limit_sec'] <= 0) {
                throw ValidationException::withMessages([
                    'response.recording_limit_sec' => 'Audio response requires positive recording_limit_sec',
                ]);
            }
        }
    }

    private function validateScoringConsistency(array $scoring, array $interaction): void
    {
        $method = $scoring['method'];

        // Check 1: keyed/partial/hybrid require answer_key
        if (in_array($method, ['keyed', 'partial', 'hybrid'])) {
            if (empty($scoring['answer_key'])) {
                throw ValidationException::withMessages([
                    'scoring.answer_key' => "Scoring method '$method' requires answer_key",
                ]);
            }
        }

        // Check 2: rubric/hybrid require criteria
        if (in_array($method, ['rubric', 'hybrid'])) {
            if (empty($scoring['rubric']['criteria'])) {
                throw ValidationException::withMessages([
                    'scoring.rubric.criteria' => "Scoring method '$method' requires rubric.criteria",
                ]);
            }

            // Check criteria structure
            foreach ($scoring['rubric']['criteria'] as $i => $criterion) {
                if (empty($criterion['name'])) {
                    throw ValidationException::withMessages(["scoring.rubric.criteria.$i.name" => 'Criterion name is required']);
                }
                if (empty($criterion['levels'])) {
                    throw ValidationException::withMessages(["scoring.rubric.criteria.$i.levels" => 'Criterion levels are required']);
                }
            }
        }

        // Check 3: For selection mode, validate answer_key references valid options
        if ($method === 'keyed' && ($interaction['response_type'] ?? '') === 'selection') {
            $options = $interaction['options'] ?? [];
            if (! empty($options) && ! empty($scoring['answer_key'])) {
                $optionIds = array_column($options, 'id');
                foreach ($scoring['answer_key'] as $key => $value) {
                    if (is_string($value) && ! in_array($value, $optionIds)) {
                        throw ValidationException::withMessages([
                            "scoring.answer_key.$key" => "Answer key references non-existent option: $value",
                        ]);
                    }
                }
            }
        }
    }

    // ========== HELPER METHODS ==========

    private function mustString(array $data, string $key): string
    {
        $parts = explode('.', $key);
        $lastKey = array_pop($parts);
        $current = $data;

        foreach ($parts as $part) {
            if (! isset($current[$part])) {
                throw ValidationException::withMessages([$key => "$key is required"]);
            }
            $current = $current[$part];
        }

        if (! isset($current[$lastKey])) {
            throw ValidationException::withMessages([$key => "$key is required"]);
        }

        if (! is_string($current[$lastKey]) || $current[$lastKey] === '') {
            throw ValidationException::withMessages([$key => "$key must be a non-empty string"]);
        }

        return $current[$lastKey];
    }

    private function mustInteger(array $data, string $key, ?int $min = null, ?int $max = null): int
    {
        $parts = explode('.', $key);
        $lastKey = array_pop($parts);
        $current = $data;

        foreach ($parts as $part) {
            if (! isset($current[$part])) {
                throw ValidationException::withMessages([$key => "$key is required"]);
            }
            $current = $current[$part];
        }

        if (! isset($current[$lastKey])) {
            throw ValidationException::withMessages([$key => "$key is required"]);
        }

        if (! is_int($current[$lastKey])) {
            throw ValidationException::withMessages([$key => "$key must be an integer"]);
        }

        $value = $current[$lastKey];

        if ($min !== null && $value < $min) {
            throw ValidationException::withMessages([$key => "$key must be >= $min"]);
        }

        if ($max !== null && $value > $max) {
            throw ValidationException::withMessages([$key => "$key must be <= $max"]);
        }

        return $value;
    }

    private function isAssoc(array $arr): bool
    {
        if (empty($arr)) {
            return true;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
