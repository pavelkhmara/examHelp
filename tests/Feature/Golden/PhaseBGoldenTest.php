<?php

namespace Tests\Feature\Golden;

use Tests\TestCase;

class PhaseBGoldenTest extends TestCase
{
    use GoldenTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGoldenTest();
    }

    /**
     * Test Phase B golden fixture structure is valid
     */
    public function test_phase_b_golden_fixture_has_required_fields(): void
    {
        $goldenId = 'pl-b1';
        $golden = $this->loadGolden($goldenId, 'phase_b');

        // Required root fields
        $this->assertArrayHasKey('stage', $golden);
        $this->assertEquals('phase_b', $golden['stage']);
        $this->assertArrayHasKey('exam_id', $golden);
        $this->assertArrayHasKey('structure_v2', $golden);

        // Structure v2 fields
        $structure = $golden['structure_v2'];
        $this->assertArrayHasKey('sections', $structure);
        $this->assertIsArray($structure['sections']);
        $this->assertNotEmpty($structure['sections'], 'Phase B should have at least one section');
    }

    /**
     * Test Phase B sections have assembly plans
     */
    public function test_phase_b_has_assembly_plans(): void
    {
        $goldenId = 'pl-b1';
        $golden = $this->loadGolden($goldenId, 'phase_b');

        $sections = $golden['structure_v2']['sections'] ?? [];
        $foundAssembly = false;

        foreach ($sections as $section) {
            if (isset($section['assembly'])) {
                $foundAssembly = true;
                $assembly = $section['assembly'];

                // Assembly should have mode
                $this->assertArrayHasKey('mode', $assembly);
                $this->assertContains(
                    $assembly['mode'],
                    ['inline', 'placeholder', 'hybrid', 'blueprint', 'pool'],
                    'Assembly mode should be valid'
                );

                // Validate mode-specific structure
                match ($assembly['mode']) {
                    'inline', 'placeholder', 'hybrid' => $this->assertTrue(
                        isset($assembly['question_groups']) || isset($assembly['tasks']),
                        "Mode {$assembly['mode']} should have question_groups or tasks"
                    ),
                    'blueprint' => $this->assertTrue(
                        isset($assembly['slots']),
                        "Mode blueprint should have slots"
                    ),
                    'pool' => $this->assertTrue(
                        isset($assembly['filters']),
                        "Mode pool should have filters"
                    ),
                };
            }
        }

        $this->assertTrue($foundAssembly, 'Phase B should have at least one section with assembly');
    }

    /**
     * Test Phase B question groups have required fields (if present)
     */
    public function test_phase_b_question_groups_have_required_fields(): void
    {
        $goldenId = 'pl-b1';
        $golden = $this->loadGolden($goldenId, 'phase_b');

        $sections = $golden['structure_v2']['sections'] ?? [];
        $foundQuestionGroups = false;

        foreach ($sections as $section) {
            if (!isset($section['assembly']['question_groups'])) {
                continue;
            }

            $foundQuestionGroups = true;
            $groups = $section['assembly']['question_groups'];
            foreach ($groups as $groupIndex => $group) {
                $this->assertArrayHasKey('id', $group, "Question group {$groupIndex} should have id");
                $this->assertArrayHasKey('questions', $group, "Question group {$groupIndex} should have questions");
                $this->assertIsArray($group['questions']);
                $this->assertNotEmpty($group['questions'], "Question group {$groupIndex} should have at least one question");

                // Check questions structure
                foreach ($group['questions'] as $qIndex => $question) {
                    $this->assertArrayHasKey('id', $question, "Question {$qIndex} should have id");
                    $this->assertArrayHasKey('type', $question, "Question {$qIndex} should have type");
                }
            }
        }

        if (!$foundQuestionGroups) {
            $this->markTestSkipped('No question_groups found in Phase B (only blueprint/pool modes)');
        }
    }

    /**
     * Test Phase B has playback settings for listening groups
     */
    public function test_phase_b_listening_groups_have_playback_settings(): void
    {
        $goldenId = 'pl-b1';
        $golden = $this->loadGolden($goldenId, 'phase_b');

        $sections = $golden['structure_v2']['sections'] ?? [];

        foreach ($sections as $section) {
            if ($section['skill'] !== 'listening') {
                continue;
            }

            if (!isset($section['assembly']['question_groups'])) {
                continue;
            }

            $groups = $section['assembly']['question_groups'];
            foreach ($groups as $group) {
                if (isset($group['playback_settings'])) {
                    $settings = $group['playback_settings'];

                    // Should have max_plays
                    $this->assertArrayHasKey('max_plays', $settings);
                    $this->assertIsInt($settings['max_plays']);
                    $this->assertGreaterThanOrEqual(0, $settings['max_plays']);

                    // If enforcement is present, should be valid
                    if (isset($settings['enforcement'])) {
                        $this->assertContains(
                            $settings['enforcement'],
                            ['strict', 'advisory', 'none'],
                            'Playback enforcement mode should be valid'
                        );
                    }
                }
            }
        }

        $this->assertTrue(true); // Test passed if no assertions failed
    }

    /**
     * Test Phase B comparison hints are defined
     */
    public function test_phase_b_has_comparison_hints(): void
    {
        $goldenId = 'pl-b1';
        $golden = $this->loadGolden($goldenId, 'phase_b');

        $this->assertArrayHasKey('_comparison_hints', $golden);

        $hints = $golden['_comparison_hints'];
        $this->assertArrayHasKey('critical_fields', $hints);
        $this->assertArrayHasKey('flexible_fields', $hints);
        $this->assertArrayHasKey('ignore_fields', $hints);
    }
}
