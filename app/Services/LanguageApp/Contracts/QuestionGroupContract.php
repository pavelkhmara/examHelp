<?php

declare(strict_types=1);

namespace App\Services\LanguageApp\Contracts;

use Webmozart\Assert\Assert;

/**
 * Contract validator for question_groups synthesis pipeline
 *
 * Enforces invariants between components to prevent silent ID/field loss.
 * This contract is FROZEN - changes require formal review process.
 *
 * @see docs/architecture/synthesis-pipeline-contracts.md
 * @see docs/architecture/synthesis-pipeline-rollout-plan.md
 */
class QuestionGroupContract
{
    /**
     * Validate filter before passing to QuestionSynthesizer
     *
     * Called in: SynthesizeTaskQuestionsJob::synthesizeQuestionGroup()
     *
     * @param array<string, mixed> $filter Filter array for single question synthesis
     * @throws \InvalidArgumentException if contract violated
     */
    public static function validateFilter(array $filter): void
    {
        Assert::keyExists($filter, 'type', 'Filter must have type');
        Assert::stringNotEmpty($filter['type'], 'Filter type cannot be empty');

        Assert::keyExists($filter, 'question_id', 'Filter must have question_id');
        Assert::stringNotEmpty($filter['question_id'], 'Filter question_id cannot be empty');

        // group_id обязателен для grouped questions
        if (isset($filter['group_id'])) {
            Assert::stringNotEmpty($filter['group_id'], 'Filter group_id cannot be empty');
        }

        // question_id формат validation (для grouped questions)
        if (isset($filter['group_id'])) {
            // Для grouped questions ID должен быть SHORT (без префикса)
            // Формат: "list-q1", NOT "listening-task-1_list-q1"
            Assert::regex(
                $filter['question_id'],
                '/^[a-z0-9_-]+$/i',
                "Question ID must be simple format (no prefix): {$filter['question_id']}"
            );
        }
    }

    /**
     * Validate question object AFTER AI generation, BEFORE attach to exam
     *
     * Called in: QuestionAttacher::attachToExam()
     *
     * @param array<string, mixed> $question Question data from AI (post-processed)
     * @param string|null $expectedGroupId Expected group_id (if grouped)
     * @throws \InvalidArgumentException if contract violated
     */
    public static function validateBeforeAttach(
        array $question,
        ?string $expectedGroupId = null
    ): void {
        Assert::keyExists($question, 'id', 'Question must have id');
        Assert::stringNotEmpty($question['id'], 'Question id cannot be empty');

        Assert::keyExists($question, 'type', 'Question must have type');
        Assert::stringNotEmpty($question['type'], 'Question type cannot be empty');

        Assert::keyExists($question, 'interaction', 'Question must have interaction');
        Assert::isArray($question['interaction'], 'Question interaction must be array');
        Assert::notEmpty($question['interaction'], 'Question interaction cannot be empty (skeleton?)');

        // group_id consistency check
        if ($expectedGroupId !== null) {
            if (empty($question['group_id'])) {
                throw new \InvalidArgumentException(
                    "Question {$question['id']} missing group_id, expected {$expectedGroupId}"
                );
            }

            if ($question['group_id'] !== $expectedGroupId) {
                throw new \InvalidArgumentException(
                    "Question {$question['id']} group_id mismatch: " .
                    "expected {$expectedGroupId}, got {$question['group_id']}"
                );
            }
        }
    }

    /**
     * Validate question_id format
     *
     * Called in: QuestionAttacher, StructureMaterializer
     *
     * @param string $questionId Full question_id for DB
     * @param bool $isGrouped Whether this is a grouped question
     * @throws \InvalidArgumentException if format invalid
     */
    public static function validateQuestionIdFormat(
        string $questionId,
        bool $isGrouped
    ): void {
        if ($isGrouped) {
            // Format: {group_id}_{raw_question_id}
            // Example: "listening-task-1_list-q1"
            Assert::regex(
                $questionId,
                '/^[a-z0-9_-]+_[a-z0-9_-]+$/i',
                "Grouped question_id must have format '{group_id}_{raw_id}': {$questionId}"
            );
        } else {
            // Format: {section_key}_{raw_question_id}
            // Example: "sec-listening-b1_q1"
            Assert::regex(
                $questionId,
                '/^sec-[a-z0-9_-]+_[a-z0-9_-]+$/i',
                "Ungrouped question_id must have format 'sec-{section}_{raw_id}': {$questionId}"
            );
        }
    }

    /**
     * Validate plan_data.question_groups structure
     *
     * Called in: SynthesizeQuestionsJob::dispatchTaskLevelJobs()
     *
     * @param array<string, mixed> $questionGroup Question group from plan_data
     * @throws \InvalidArgumentException if structure invalid
     */
    public static function validateQuestionGroupSpec(array $questionGroup): void
    {
        Assert::keyExists($questionGroup, 'id', 'Question group must have id');
        Assert::stringNotEmpty($questionGroup['id'], 'Question group id cannot be empty');

        Assert::keyExists($questionGroup, 'questions', 'Question group must have questions array');
        Assert::isArray($questionGroup['questions'], 'Questions must be array');
        Assert::notEmpty($questionGroup['questions'], 'Question group must have at least 1 question');

        foreach ($questionGroup['questions'] as $index => $q) {
            Assert::keyExists($q, 'id', "Question at index {$index} must have id");
            Assert::stringNotEmpty($q['id'], "Question at index {$index} id cannot be empty");

            Assert::keyExists($q, 'type', "Question at index {$index} must have type");
            Assert::stringNotEmpty($q['type'], "Question at index {$index} type cannot be empty");
        }
    }
}
