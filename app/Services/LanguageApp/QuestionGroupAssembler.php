<?php

namespace App\Services\LanguageApp;

use App\Services\LanguageApp\Validators\QuestionGroupValidator;
use Illuminate\Support\Facades\Log;

/**
 * Question Group Assembler Service
 *
 * Processes question groups from Phase B assembly config.
 * Validates group structure and prepares plan data for generation.
 *
 * Question groups are used when multiple questions share a common stimulus
 * (e.g., listening comprehension with 6-10 questions based on one audio file).
 */
class QuestionGroupAssembler
{
    protected QuestionGroupValidator $validator;

    public function __construct(QuestionGroupValidator $validator)
    {
        $this->validator = $validator;
    }

    /**
     * Process question groups from assembly config
     *
     * Input structure:
     * {
     *   "question_groups": [
     *     {
     *       "id": "listening-group-1",
     *       "title": "Zadanie I",
     *       "stimulus": { "audio": ["https://example.com/audio.mp3"] },
     *       "playback_settings": { "max_plays": 1, "enforcement": "strict" },
     *       "questions": [
     *         { "id": "q1", "type": "single_select", ... },
     *         { "id": "q2", "type": "single_select", ... }
     *       ]
     *     }
     *   ]
     * }
     *
     * Output:
     * {
     *   "plan_data": {
     *     "question_groups": [ ... validated groups ... ]
     *   },
     *   "total_questions": 6
     * }
     *
     * @param  array  $questionGroups  Array of question group configs
     * @param  string  $sectionId  Section ID for logging
     * @return array Result with plan_data and total_questions
     *
     * @throws \Exception If validation fails or groups are invalid
     */
    public function assemble(array $questionGroups, string $sectionId): array
    {
        Log::info('[QuestionGroupAssembler] Starting assembly', [
            'section_id' => $sectionId,
            'groups_count' => count($questionGroups),
        ]);

        if (empty($questionGroups)) {
            throw new \Exception("Question groups array is empty for section {$sectionId}");
        }

        $validatedGroups = [];
        $totalQuestions = 0;

        foreach ($questionGroups as $index => $group) {
            $groupId = $group['id'] ?? "group_{$index}";

            Log::debug('[QuestionGroupAssembler] Processing group', [
                'section_id' => $sectionId,
                'group_id' => $groupId,
                'group_index' => $index,
            ]);

            // Validate group structure
            try {
                $validatedGroup = $this->validateGroup($group, $groupId, $sectionId);
                $validatedGroups[] = $validatedGroup;

                // Count questions in this group
                $questionsInGroup = count($validatedGroup['questions'] ?? []);
                $totalQuestions += $questionsInGroup;

                Log::debug('[QuestionGroupAssembler] Group validated', [
                    'section_id' => $sectionId,
                    'group_id' => $groupId,
                    'questions_count' => $questionsInGroup,
                ]);
            } catch (\Throwable $e) {
                Log::error('[QuestionGroupAssembler] Group validation failed', [
                    'section_id' => $sectionId,
                    'group_id' => $groupId,
                    'error' => $e->getMessage(),
                ]);

                throw new \Exception(
                    "Question group '{$groupId}' validation failed for section {$sectionId}: ".$e->getMessage()
                );
            }
        }

        Log::info('[QuestionGroupAssembler] Assembly completed', [
            'section_id' => $sectionId,
            'groups_count' => count($validatedGroups),
            'total_questions' => $totalQuestions,
        ]);

        return [
            'plan_data' => [
                'question_groups' => $validatedGroups,
            ],
            'total_questions' => $totalQuestions,
        ];
    }

    /**
     * Validate and normalize a single question group
     *
     * @param  array  $group  Question group data
     * @param  string  $groupId  Group identifier for error messages
     * @param  string  $sectionId  Section ID for error messages
     * @return array Validated and normalized group
     *
     * @throws \Exception If validation fails
     */
    protected function validateGroup(array $group, string $groupId, string $sectionId): array
    {
        // Check required fields
        if (empty($group['id'])) {
            throw new \Exception("Group missing required field 'id' in section {$sectionId}");
        }

        if (empty($group['title'])) {
            throw new \Exception("Group '{$groupId}' missing required field 'title' in section {$sectionId}");
        }

        if (! isset($group['stimulus'])) {
            throw new \Exception("Group '{$groupId}' missing required field 'stimulus' in section {$sectionId}");
        }

        if (! isset($group['questions']) || ! is_array($group['questions'])) {
            throw new \Exception("Group '{$groupId}' missing 'questions' array in section {$sectionId}");
        }

        // Validate minimum questions count
        $questionsCount = count($group['questions']);
        if ($questionsCount < 2) {
            throw new \Exception(
                "Group '{$groupId}' must have at least 2 questions, got {$questionsCount} in section {$sectionId}"
            );
        }

        if ($questionsCount > 20) {
            throw new \Exception(
                "Group '{$groupId}' cannot have more than 20 questions, got {$questionsCount} in section {$sectionId}"
            );
        }

        // AUTO-FIX: Validate stimulus is not empty, add placeholder if empty
        $stimulus = $group['stimulus'];
        if (is_array($stimulus)) {
            $hasContent = false;
            foreach (['text_html', 'images', 'audio', 'video'] as $key) {
                if (! empty($stimulus[$key])) {
                    $hasContent = true;
                    break;
                }
            }

            if (! $hasContent) {
                // AUTO-FIX: Add placeholder text instead of throwing error
                $group['stimulus']['text_html'] = '<p>[Stimulus content will be provided]</p>';

                \Log::warning('[QuestionGroupAssembler] Auto-fixed empty stimulus', [
                    'group_id' => $groupId,
                    'section_id' => $sectionId,
                ]);
            }
        }

        // Validate playback_settings if present
        if (isset($group['playback_settings'])) {
            $this->validatePlaybackSettings($group['playback_settings'], $groupId, $sectionId);
        }

        // Run full validator (will throw ValidationException if fails)
        $this->validator->validate($group);

        // Normalize and return
        return $this->normalizeGroup($group);
    }

    /**
     * Validate playback settings
     *
     * @throws \Exception If playback settings are invalid
     */
    protected function validatePlaybackSettings(array $settings, string $groupId, string $sectionId): void
    {
        // Validate max_plays
        if (array_key_exists('max_plays', $settings)) {
            $maxPlays = $settings['max_plays'];
            if (! is_null($maxPlays) && (! is_int($maxPlays) || $maxPlays < 1)) {
                throw new \Exception(
                    "Group '{$groupId}' playback_settings.max_plays must be a positive integer or null, ".
                    'got: '.var_export($maxPlays, true)." in section {$sectionId}"
                );
            }
        }

        // Validate enforcement
        if (isset($settings['enforcement'])) {
            $enforcement = $settings['enforcement'];
            $validModes = ['strict', 'advisory', 'none'];
            if (! in_array($enforcement, $validModes, true)) {
                throw new \Exception(
                    "Group '{$groupId}' playback_settings.enforcement must be one of: ".implode(', ', $validModes).
                    ", got: '{$enforcement}' in section {$sectionId}"
                );
            }
        }
    }

    /**
     * Normalize group data (set defaults, clean up)
     *
     * @param  array  $group  Raw group data
     * @return array Normalized group data
     */
    protected function normalizeGroup(array $group): array
    {
        // Set defaults for playback_settings
        if (isset($group['playback_settings'])) {
            $group['playback_settings'] = array_merge([
                'max_plays' => null,
                'enforcement' => 'none',
                'show_counter' => true,
                'reset_on_navigation' => true,
            ], $group['playback_settings']);
        }

        // Set default order if not present
        if (! isset($group['order'])) {
            $group['order'] = 0;
        }

        // Ensure questions is array
        if (! isset($group['questions'])) {
            $group['questions'] = [];
        }

        // ✅ FIX: Set group_id for FK relationship (FK Bug Fix #3 2025-11-27)
        // Add both group_id (for synthesis) and question_group_ref (for tracking)
        foreach ($group['questions'] as $index => &$question) {
            $question['group_id'] = $group['id']; // ✅ FK field for synthesis
            $question['question_group_ref'] = $group['id']; // Tracking field

            // Ensure each question has an id
            if (empty($question['id'])) {
                $question['id'] = $group['id'].'_q'.($index + 1);
            }
        }

        return $group;
    }

    /**
     * Calculate total questions from question groups
     *
     * @param  array  $questionGroups  Array of question groups
     * @return int Total questions count
     */
    public function calculateTotalQuestions(array $questionGroups): int
    {
        $total = 0;

        foreach ($questionGroups as $group) {
            if (isset($group['questions']) && is_array($group['questions'])) {
                $total += count($group['questions']);
            }
        }

        return $total;
    }
}
