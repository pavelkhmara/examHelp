<?php

namespace App\Services\LanguageApp\Validators;

use Illuminate\Validation\ValidationException;

/**
 * JsonSchemaExamV2 - Validator for v2 Exam Archetype
 *
 * Validates exam structure according to s2_exam_json_archetype_v2.json
 *
 * Required top-level fields:
 * - id (string)
 * - meta (object with name, language, level)
 * - pass_policy (object with mode)
 * - policies (object)
 * - sections (array of section objects)
 *
 * Each section must have:
 * - id, skill, duration_min, max_score, min_pass_percent
 */
final class JsonSchemaExamV2
{
    private const VALID_SKILLS = [
        'listening',
        'reading',
        'writing',
        'speaking',
        'use_of_english',
        'grammar_lexis',
    ];

    private const VALID_PASS_POLICY_MODES = [
        'overall_only',
        'per_section_only',
        'mixed',
    ];

    private const VALID_TIME_POLICIES = [
        'per_section',
        'per_task',
        'hybrid',
    ];

    private const VALID_ASSEMBLY_MODES = [
        'pool',
        'blueprint',
        'inline',
    ];

    public function validate(mixed $data): array
    {
        if (! is_array($data) || ! $this->isAssoc($data)) {
            throw ValidationException::withMessages(['root' => 'Exam must be a JSON object']);
        }

        // Required fields
        $id = $this->mustString($data, 'id');
        $meta = $this->validateMeta($data['meta'] ?? null);
        $passPolicy = $this->validatePassPolicy($data['pass_policy'] ?? null);
        $policies = $this->validatePolicies($data['policies'] ?? null);
        $sections = $this->validateSections($data['sections'] ?? null);

        // Optional fields
        $version = $data['version'] ?? '2.0';
        $resources = $data['resources'] ?? null;
        $rubrics = $data['rubrics'] ?? null;

        // Additional logical checks
        $this->validateLogic($data, $passPolicy, $sections);

        return [
            'id' => $id,
            'version' => $version,
            'meta' => $meta,
            'pass_policy' => $passPolicy,
            'policies' => $policies,
            'sections' => $sections,
            'resources' => $resources,
            'rubrics' => $rubrics,
        ];
    }

    private function validateMeta(?array $meta): array
    {
        if (! is_array($meta)) {
            throw ValidationException::withMessages(['meta' => 'meta is required and must be an object']);
        }

        $name = $this->mustString($meta, 'name');
        $language = $this->mustString($meta, 'language');
        $level = $this->mustString($meta, 'level');

        // Validate CEFR level
        if (! in_array($level, ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'])) {
            throw ValidationException::withMessages(['meta.level' => 'Invalid CEFR level. Must be one of: A1, A2, B1, B2, C1, C2']);
        }

        return [
            'name' => $name,
            'language' => $language,
            'level' => $level,
            'provider' => $meta['provider'] ?? null,
            'session' => $meta['session'] ?? null,
            'tags' => $meta['tags'] ?? null,
        ];
    }

    private function validatePassPolicy(?array $policy): array
    {
        if (! is_array($policy)) {
            throw ValidationException::withMessages(['pass_policy' => 'pass_policy is required']);
        }

        $mode = $this->mustString($policy, 'mode');

        if (! in_array($mode, self::VALID_PASS_POLICY_MODES)) {
            throw ValidationException::withMessages([
                'pass_policy.mode' => 'Invalid mode. Must be one of: '.implode(', ', self::VALID_PASS_POLICY_MODES),
            ]);
        }

        $result = ['mode' => $mode];

        if (isset($policy['overall_threshold_percent'])) {
            $result['overall_threshold_percent'] = $this->mustNumber($policy, 'overall_threshold_percent', 0, 100);
        }

        if (isset($policy['per_section_threshold_percent'])) {
            $result['per_section_threshold_percent'] = $this->mustNumber($policy, 'per_section_threshold_percent', 0, 100);
        }

        return $result;
    }

    private function validatePolicies(?array $policies): array
    {
        if (! is_array($policies)) {
            throw ValidationException::withMessages(['policies' => 'policies is required and must be an object']);
        }

        // Policies is a free-form object, minimal validation
        return $policies;
    }

    private function validateSections(?array $sections): array
    {
        if (! is_array($sections) || empty($sections)) {
            throw ValidationException::withMessages(['sections' => 'sections is required and must be a non-empty array']);
        }

        $validated = [];
        $sectionIds = [];

        foreach ($sections as $i => $section) {
            if (! is_array($section)) {
                throw ValidationException::withMessages(["sections.$i" => 'Section must be an object']);
            }

            $validated[] = $this->validateSection($section, $i);
            $sectionIds[] = $section['id'] ?? null;
        }

        // Check for duplicate section IDs
        $duplicates = array_filter(array_count_values($sectionIds), fn ($count) => $count > 1);
        if (! empty($duplicates)) {
            throw ValidationException::withMessages(['sections' => 'Duplicate section IDs found: '.implode(', ', array_keys($duplicates))]);
        }

        return $validated;
    }

    private function validateSection(array $section, int $index): array
    {
        $prefix = "sections.$index";

        // Required fields
        $id = $this->mustString($section, 'id');
        $skill = $this->mustString($section, 'skill');
        $durationMin = $this->mustInteger($section, 'duration_min', 1);
        $maxScore = $this->mustNumber($section, 'max_score', 0);
        $minPassPercent = $this->mustNumber($section, 'min_pass_percent', 0, 100);

        // Validate skill
        if (! in_array($skill, self::VALID_SKILLS)) {
            throw ValidationException::withMessages([
                "$prefix.skill" => 'Invalid skill. Must be one of: '.implode(', ', self::VALID_SKILLS),
            ]);
        }

        // Optional fields
        $result = [
            'id' => $id,
            'skill' => $skill,
            'duration_min' => $durationMin,
            'max_score' => $maxScore,
            'min_pass_percent' => $minPassPercent,
        ];

        if (isset($section['title'])) {
            $result['title'] = (string) $section['title'];
        }

        if (isset($section['time_policy'])) {
            $timePolicy = (string) $section['time_policy'];
            if (! in_array($timePolicy, self::VALID_TIME_POLICIES)) {
                throw ValidationException::withMessages([
                    "$prefix.time_policy" => 'Invalid time_policy. Must be one of: '.implode(', ', self::VALID_TIME_POLICIES),
                ]);
            }
            $result['time_policy'] = $timePolicy;
        }

        if (isset($section['weight'])) {
            $result['weight'] = $this->mustNumber($section, 'weight', 0, 1);
        }

        if (isset($section['pair_mode'])) {
            $result['pair_mode'] = (string) $section['pair_mode'];
        }

        if (isset($section['playbacks'])) {
            $result['playbacks'] = $this->mustInteger($section, 'playbacks', 1, 2);
        }

        if (isset($section['phases'])) {
            $result['phases'] = is_array($section['phases']) ? $section['phases'] : null;
        }

        if (isset($section['navigation'])) {
            $result['navigation'] = is_array($section['navigation']) ? $section['navigation'] : null;
        }

        if (isset($section['expected_task_count'])) {
            $result['expected_task_count'] = $this->validateExpectedTaskCount($section['expected_task_count'], $prefix);
        }

        if (isset($section['assembly'])) {
            $result['assembly'] = $this->validateAssembly($section['assembly'], $prefix);
        }

        if (isset($section['overrides'])) {
            $result['overrides'] = $section['overrides'];
        }

        if (isset($section['tasks'])) {
            $result['tasks'] = is_array($section['tasks']) ? $section['tasks'] : null;
        }

        if (isset($section['question_archetypes'])) {
            $result['question_archetypes'] = $this->validateTaskArchetypes($section['question_archetypes'], $prefix);
        }

        return $result;
    }

    private function validateExpectedTaskCount(mixed $data, string $prefix): array
    {
        if (! is_array($data)) {
            throw ValidationException::withMessages(["$prefix.expected_task_count" => 'expected_task_count must be an object']);
        }

        $result = [];

        if (isset($data['target'])) {
            $result['target'] = $this->mustInteger($data, 'target', 1);
        }

        if (isset($data['min'])) {
            $result['min'] = $this->mustInteger($data, 'min', 0);
        }

        if (isset($data['max'])) {
            $result['max'] = $this->mustInteger($data, 'max', 1);
        }

        return $result;
    }

    private function validateAssembly(mixed $data, string $prefix): array
    {
        if (! is_array($data)) {
            throw ValidationException::withMessages(["$prefix.assembly" => 'assembly must be an object']);
        }

        $mode = $this->mustString($data, 'mode');

        if (! in_array($mode, self::VALID_ASSEMBLY_MODES)) {
            throw ValidationException::withMessages([
                "$prefix.assembly.mode" => 'Invalid assembly mode. Must be one of: '.implode(', ', self::VALID_ASSEMBLY_MODES),
            ]);
        }

        $result = ['mode' => $mode];

        // Mode-specific validation
        if ($mode === 'pool') {
            $result['pool_id'] = $this->mustString($data, 'pool_id');
            $result['pick'] = $this->mustInteger($data, 'pick', 1);

            if (isset($data['filters'])) {
                $result['filters'] = is_array($data['filters']) ? $data['filters'] : null;
            }
            if (isset($data['seed'])) {
                $result['seed'] = (string) $data['seed'];
            }
            if (isset($data['assertions'])) {
                $result['assertions'] = $this->validateAssertions($data['assertions'], "$prefix.assembly");
            }
        } elseif ($mode === 'blueprint') {
            if (! isset($data['blueprint']) || ! is_array($data['blueprint']) || empty($data['blueprint'])) {
                throw ValidationException::withMessages(["$prefix.assembly.blueprint" => 'blueprint is required for mode:blueprint']);
            }
            $result['blueprint'] = $data['blueprint'];

            if (isset($data['seed'])) {
                $result['seed'] = (string) $data['seed'];
            }
            if (isset($data['assertions'])) {
                $result['assertions'] = $this->validateAssertions($data['assertions'], "$prefix.assembly");
            }
        }
        // inline mode requires no additional fields

        return $result;
    }

    private function validateAssertions(mixed $data, string $prefix): array
    {
        if (! is_array($data)) {
            return [];
        }

        $result = [];

        if (isset($data['total_tasks_equals'])) {
            $result['total_tasks_equals'] = $this->mustInteger($data, 'total_tasks_equals', 1);
        }

        if (isset($data['unique_by'])) {
            $result['unique_by'] = is_array($data['unique_by']) ? $data['unique_by'] : null;
        }

        return $result;
    }

    private function validateTaskArchetypes(mixed $data, string $prefix): array
    {
        if (! is_array($data)) {
            throw ValidationException::withMessages(["$prefix.question_archetypes" => 'question_archetypes must be an array']);
        }

        if (empty($data)) {
            throw ValidationException::withMessages(["$prefix.question_archetypes" => 'question_archetypes cannot be empty if present']);
        }

        $validated = [];
        foreach ($data as $i => $archetype) {
            if (! is_array($archetype)) {
                throw ValidationException::withMessages(["$prefix.question_archetypes.$i" => 'Each archetype must be an object']);
            }

            $validatedArchetype = [
                'id' => $this->mustString($archetype, 'id'),
                'type' => $this->mustString($archetype, 'type'),
                'name' => $this->mustString($archetype, 'name'),
            ];

            // Optional fields
            if (isset($archetype['difficulty'])) {
                $difficulty = (string) $archetype['difficulty'];
                if (! in_array($difficulty, ['easy', 'medium', 'hard'])) {
                    throw ValidationException::withMessages([
                        "$prefix.question_archetypes.$i.difficulty" => 'difficulty must be one of: easy, medium, hard',
                    ]);
                }
                $validatedArchetype['difficulty'] = $difficulty;
            }

            if (isset($archetype['config'])) {
                $validatedArchetype['config'] = is_array($archetype['config']) ? $archetype['config'] : null;
            }

            $validated[] = $validatedArchetype;
        }

        return $validated;
    }

    private function validateLogic(array $data, array $passPolicy, array $sections): void
    {
        // Check pass_policy consistency
        $mode = $passPolicy['mode'];

        if (in_array($mode, ['overall_only', 'mixed'])) {
            if (! isset($passPolicy['overall_threshold_percent'])) {
                throw ValidationException::withMessages([
                    'pass_policy.overall_threshold_percent' => "overall_threshold_percent is required for mode: $mode",
                ]);
            }
        }

        if (in_array($mode, ['per_section_only', 'mixed'])) {
            if (! isset($passPolicy['per_section_threshold_percent'])) {
                throw ValidationException::withMessages([
                    'pass_policy.per_section_threshold_percent' => "per_section_threshold_percent is required for mode: $mode",
                ]);
            }
        }

        // Additional checks can be added here
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

    private function mustNumber(array $data, string $key, ?float $min = null, ?float $max = null): float
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

        if (! is_numeric($current[$lastKey])) {
            throw ValidationException::withMessages([$key => "$key must be a number"]);
        }

        $value = (float) $current[$lastKey];

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
