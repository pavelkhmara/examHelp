<?php

namespace Tests\Feature\Golden;

use Tests\TestCase;

class PhaseAGoldenTest extends TestCase
{
    use GoldenTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGoldenTest();
    }

    /**
     * Test Phase A golden fixture structure is valid
     */
    public function test_phase_a_golden_fixture_has_required_fields(): void
    {
        $goldenId = 'pl-b1';
        $golden = $this->loadGolden($goldenId, 'phase_a');

        // Required root fields
        $this->assertArrayHasKey('stage', $golden);
        $this->assertEquals('phase_a', $golden['stage']);
        $this->assertArrayHasKey('exam_id', $golden);
        $this->assertArrayHasKey('structure_v2', $golden);

        // Structure v2 fields
        $structure = $golden['structure_v2'];
        $this->assertArrayHasKey('meta', $structure);
        $this->assertArrayHasKey('pass_policy', $structure);
        $this->assertArrayHasKey('sections', $structure);
        $this->assertIsArray($structure['sections']);
        $this->assertNotEmpty($structure['sections'], 'Phase A should have at least one section');
    }

    /**
     * Test Phase A sections have no assembly plans (skeleton only)
     */
    public function test_phase_a_has_no_assembly_plans(): void
    {
        $goldenId = 'pl-b1';
        $golden = $this->loadGolden($goldenId, 'phase_a');

        $sections = $golden['structure_v2']['sections'] ?? [];

        foreach ($sections as $index => $section) {
            $this->assertArrayNotHasKey(
                'assembly',
                $section,
                "Phase A section {$index} should not have assembly plans"
            );
            $this->assertArrayNotHasKey(
                'tasks',
                $section,
                "Phase A section {$index} should not have tasks"
            );
            $this->assertArrayNotHasKey(
                'question_groups',
                $section,
                "Phase A section {$index} should not have question_groups"
            );
        }
    }

    /**
     * Test Phase A sections have required fields
     */
    public function test_phase_a_sections_have_required_fields(): void
    {
        $goldenId = 'pl-b1';
        $golden = $this->loadGolden($goldenId, 'phase_a');

        $sections = $golden['structure_v2']['sections'] ?? [];

        foreach ($sections as $index => $section) {
            $this->assertArrayHasKey('id', $section, "Section {$index} should have id");
            $this->assertArrayHasKey('skill', $section, "Section {$index} should have skill");
            $this->assertArrayHasKey('duration_min', $section, "Section {$index} should have duration_min");
            $this->assertArrayHasKey('max_score', $section, "Section {$index} should have max_score");

            // Validate types
            $this->assertIsString($section['id']);
            $this->assertIsString($section['skill']);
            $this->assertIsNumeric($section['duration_min']);
            $this->assertIsNumeric($section['max_score']);
        }
    }

    /**
     * Test Phase A pass_policy is valid
     */
    public function test_phase_a_pass_policy_is_valid(): void
    {
        $goldenId = 'pl-b1';
        $golden = $this->loadGolden($goldenId, 'phase_a');

        $passPolicy = $golden['structure_v2']['pass_policy'] ?? null;

        $this->assertNotNull($passPolicy, 'Phase A should have pass_policy');
        $this->assertArrayHasKey('mode', $passPolicy, 'pass_policy should have mode');
        $this->assertContains(
            $passPolicy['mode'],
            ['overall_only', 'per_section_only', 'mixed'],
            'pass_policy mode should be valid'
        );
    }

    /**
     * Test Phase A comparison hints are defined
     */
    public function test_phase_a_has_comparison_hints(): void
    {
        $goldenId = 'pl-b1';
        $golden = $this->loadGolden($goldenId, 'phase_a');

        $this->assertArrayHasKey('_comparison_hints', $golden);

        $hints = $golden['_comparison_hints'];
        $this->assertArrayHasKey('critical_fields', $hints);
        $this->assertArrayHasKey('flexible_fields', $hints);
        $this->assertArrayHasKey('ignore_fields', $hints);

        $this->assertIsArray($hints['critical_fields']);
        $this->assertNotEmpty($hints['critical_fields'], 'Should have at least one critical field');
    }

    /**
     * Test Phase A meta structure
     */
    public function test_phase_a_meta_structure_is_valid(): void
    {
        $goldenId = 'pl-b1';
        $golden = $this->loadGolden($goldenId, 'phase_a');

        $meta = $golden['structure_v2']['meta'] ?? null;

        $this->assertNotNull($meta, 'Phase A should have meta');
        $this->assertArrayHasKey('name', $meta);
        $this->assertArrayHasKey('language', $meta);
        $this->assertArrayHasKey('level', $meta);

        $this->assertIsString($meta['name']);
        $this->assertIsString($meta['language']);
        $this->assertIsString($meta['level']);
    }
}
