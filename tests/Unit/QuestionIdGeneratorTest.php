<?php

namespace Tests\Unit;

use App\Services\LanguageApp\QuestionIdGenerator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QuestionIdGenerator.
 *
 * @covers \App\Services\LanguageApp\QuestionIdGenerator
 */
class QuestionIdGeneratorTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider groupedQuestionProvider
     */
    public function it_generates_grouped_question_ids(string $groupId, string $questionId, string $expected): void
    {
        $result = QuestionIdGenerator::forGroupedQuestion($groupId, $questionId);

        $this->assertSame($expected, $result);
    }

    /**
     * @test
     *
     * @dataProvider ungroupedQuestionProvider
     */
    public function it_generates_ungrouped_question_ids(string $sectionKey, string $questionId, string $expected): void
    {
        $result = QuestionIdGenerator::forUngroupedQuestion($sectionKey, $questionId);

        $this->assertSame($expected, $result);
    }

    /**
     * @test
     */
    public function it_throws_exception_for_empty_group_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('group_id cannot be empty');

        QuestionIdGenerator::forGroupedQuestion('', 'q1');
    }

    /**
     * @test
     */
    public function it_throws_exception_for_empty_question_id_in_grouped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('question_id cannot be empty');

        QuestionIdGenerator::forGroupedQuestion('group-1', '');
    }

    /**
     * @test
     */
    public function it_throws_exception_for_invalid_characters_in_group_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('group_id must contain only letters, numbers, and hyphens');

        QuestionIdGenerator::forGroupedQuestion('group@123', 'q1');
    }

    /**
     * @test
     */
    public function it_throws_exception_for_invalid_characters_in_question_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('question_id must contain only letters, numbers, and hyphens');

        QuestionIdGenerator::forGroupedQuestion('group-1', 'q#1');
    }

    /**
     * @test
     */
    public function it_throws_exception_for_empty_section_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('section_key cannot be empty');

        QuestionIdGenerator::forUngroupedQuestion('', 'q1');
    }

    /**
     * @test
     *
     * @dataProvider validIdProvider
     */
    public function it_validates_correct_ids(string $questionId): void
    {
        $result = QuestionIdGenerator::isValid($questionId);

        $this->assertTrue($result, "Expected '{$questionId}' to be valid");
    }

    /**
     * @test
     *
     * @dataProvider invalidIdProvider
     */
    public function it_rejects_invalid_ids(string $questionId): void
    {
        $result = QuestionIdGenerator::isValid($questionId);

        $this->assertFalse($result, "Expected '{$questionId}' to be invalid");
    }

    /**
     * @test
     *
     * @dataProvider parseIdProvider
     */
    public function it_parses_question_ids(string $questionId, array $expected): void
    {
        $result = QuestionIdGenerator::parse($questionId);

        $this->assertSame($expected['prefix'], $result['prefix']);
        $this->assertSame($expected['question_id'], $result['question_id']);
        $this->assertSame($expected['is_grouped'], $result['is_grouped']);
    }

    /**
     * @test
     */
    public function it_throws_exception_when_parsing_invalid_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid question_id format');

        QuestionIdGenerator::parse('invalid-no-separator');
    }

    /**
     * Data providers
     */
    public static function groupedQuestionProvider(): array
    {
        return [
            'listening task' => [
                'groupId' => 'listening-task-1',
                'questionId' => 'list-q1',
                'expected' => 'listening-task-1_list-q1',
            ],
            'reading passage' => [
                'groupId' => 'reading-passage-2',
                'questionId' => 'read-q5',
                'expected' => 'reading-passage-2_read-q5',
            ],
            'simple numeric' => [
                'groupId' => 'group-123',
                'questionId' => 'q456',
                'expected' => 'group-123_q456',
            ],
            'complex question id' => [
                'groupId' => 'task-1',
                'questionId' => 'sub-question-2-part-a',
                'expected' => 'task-1_sub-question-2-part-a',
            ],
        ];
    }

    public static function ungroupedQuestionProvider(): array
    {
        return [
            'simple section' => [
                'sectionKey' => 'reading',
                'questionId' => 'q1',
                'expected' => 'reading_q1',
            ],
            'section with prefix' => [
                'sectionKey' => 'sec-listening',
                'questionId' => 'q10',
                'expected' => 'sec-listening_q10',
            ],
            'writing section' => [
                'sectionKey' => 'writing',
                'questionId' => 'essay-1',
                'expected' => 'writing_essay-1',
            ],
        ];
    }

    public static function validIdProvider(): array
    {
        return [
            ['listening-task-1_list-q1'],
            ['reading_q1'],
            ['sec-listening_q10'],
            ['group-123_q456'],
            ['task-1_sub-question-2'],
            ['a_b'], // Minimum valid (2 components)
            ['a-1_b-2_c-3'], // Multiple separators
        ];
    }

    public static function invalidIdProvider(): array
    {
        return [
            [''], // Empty
            ['no-separator'], // No separator
            ['has space_q1'], // Space in component
            ['has@symbol_q1'], // Special character
            ['has.dot_q1'], // Dot
            ['_leadingunderscore'], // Leading separator
            ['trailingunderscore_'], // Trailing separator
            ['double__underscore'], // Double separator (empty component)
        ];
    }

    public static function parseIdProvider(): array
    {
        return [
            'grouped listening' => [
                'questionId' => 'listening-task-1_list-q1',
                'expected' => [
                    'prefix' => 'listening-task-1',
                    'question_id' => 'list-q1',
                    'is_grouped' => true, // Has hyphen in prefix
                ],
            ],
            'ungrouped reading' => [
                'questionId' => 'reading_q1',
                'expected' => [
                    'prefix' => 'reading',
                    'question_id' => 'q1',
                    'is_grouped' => false, // No hyphen in prefix
                ],
            ],
            'ungrouped with prefix' => [
                'questionId' => 'sec-listening_q10',
                'expected' => [
                    'prefix' => 'sec-listening',
                    'question_id' => 'q10',
                    'is_grouped' => true, // Has hyphen in prefix
                ],
            ],
            'complex question id' => [
                'questionId' => 'task-1_sub-question-2',
                'expected' => [
                    'prefix' => 'task-1',
                    'question_id' => 'sub-question-2',
                    'is_grouped' => true,
                ],
            ],
            'multiple separators' => [
                'questionId' => 'group-1_part-a_item-1',
                'expected' => [
                    'prefix' => 'group-1',
                    'question_id' => 'part-a_item-1', // Rejoined
                    'is_grouped' => true,
                ],
            ],
        ];
    }
}
