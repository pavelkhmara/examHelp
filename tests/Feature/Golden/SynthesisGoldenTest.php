<?php

namespace Tests\Feature\Golden;

use Tests\TestCase;

class SynthesisGoldenTest extends TestCase
{
    use GoldenTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGoldenTest();
    }

    /**
     * Test Synthesis golden fixture structure is valid
     */
    public function test_synthesis_golden_fixture_has_required_fields(): void
    {
        $goldenId = 'pl-b1';
        $golden = $this->loadGolden($goldenId, 'synthesis');

        // Required root fields
        $this->assertArrayHasKey('stage', $golden);
        $this->assertEquals('synthesis', $golden['stage']);
        $this->assertArrayHasKey('exam_id', $golden);
        $this->assertArrayHasKey('sections', $golden);
        $this->assertIsArray($golden['sections']);
        $this->assertNotEmpty($golden['sections'], 'Synthesis should have at least one section');
    }

    /**
     * Test Synthesis sections have questions
     */
    public function test_synthesis_sections_have_questions(): void
    {
        $goldenId = 'pl-b1';
        $golden = $this->loadGolden($goldenId, 'synthesis');

        $sections = $golden['sections'] ?? [];
        $foundQuestions = false;

        foreach ($sections as $section) {
            // Check question_groups
            if (isset($section['question_groups'])) {
                foreach ($section['question_groups'] as $group) {
                    if (isset($group['questions']) && !empty($group['questions'])) {
                        $foundQuestions = true;
                        break 2;
                    }
                }
            }

            // Check ungrouped_questions
            if (isset($section['ungrouped_questions']) && !empty($section['ungrouped_questions'])) {
                $foundQuestions = true;
                break;
            }
        }

        $this->assertTrue($foundQuestions, 'Synthesis should have at least some questions');
    }

    /**
     * Test Synthesis questions have required skeleton fields
     */
    public function test_synthesis_questions_have_required_fields(): void
    {
        $goldenId = 'pl-b1';
        $golden = $this->loadGolden($goldenId, 'synthesis');

        $sections = $golden['sections'] ?? [];
        $checkedQuestions = 0;

        foreach ($sections as $section) {
            // Check question_groups
            if (isset($section['question_groups'])) {
                foreach ($section['question_groups'] as $group) {
                    $questions = $group['questions'] ?? [];
                    foreach ($questions as $question) {
                        $this->assertArrayHasKey('question_id', $question);
                        $this->assertArrayHasKey('type', $question);
                        $this->assertArrayHasKey('order', $question);

                        $checkedQuestions++;
                    }
                }
            }

            // Check ungrouped_questions
            if (isset($section['ungrouped_questions'])) {
                foreach ($section['ungrouped_questions'] as $question) {
                    $this->assertArrayHasKey('question_id', $question);
                    $this->assertArrayHasKey('type', $question);
                    $this->assertArrayHasKey('order', $question);

                    $checkedQuestions++;
                }
            }
        }

        $this->assertGreaterThan(0, $checkedQuestions, 'Should have checked at least one question');
    }

    /**
     * Test Synthesis question groups have group_id
     */
    public function test_synthesis_question_groups_have_group_id(): void
    {
        $goldenId = 'pl-b1';
        $golden = $this->loadGolden($goldenId, 'synthesis');

        $sections = $golden['sections'] ?? [];

        foreach ($sections as $section) {
            if (!isset($section['question_groups'])) {
                continue;
            }

            foreach ($section['question_groups'] as $groupIndex => $group) {
                $this->assertArrayHasKey('group_id', $group, "Question group {$groupIndex} should have group_id");
                $this->assertArrayHasKey('title', $group, "Question group {$groupIndex} should have title");
                $this->assertArrayHasKey('questions', $group, "Question group {$groupIndex} should have questions");
            }
        }

        $this->assertTrue(true); // Test passed if no assertions failed
    }

    /**
     * Test Synthesis comparison hints are defined
     */
    public function test_synthesis_has_comparison_hints(): void
    {
        $goldenId = 'pl-b1';
        $golden = $this->loadGolden($goldenId, 'synthesis');

        $this->assertArrayHasKey('_comparison_hints', $golden);

        $hints = $golden['_comparison_hints'];
        $this->assertArrayHasKey('critical_fields', $hints);
        $this->assertArrayHasKey('flexible_fields', $hints);
        $this->assertArrayHasKey('ignore_fields', $hints);
    }
}
