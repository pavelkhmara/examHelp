<?php

namespace Tests\Feature\Golden;

use Tests\TestCase;

class IdentityGoldenTest extends TestCase
{
    use GoldenTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGoldenTest();
    }

    /**
     * Test Identity golden fixture structure is valid
     */
    public function test_identity_golden_fixture_has_required_fields(): void
    {
        $goldenId = 'pl-b1';
        $golden = $this->loadGolden($goldenId, 'identity');

        // Required root fields
        $this->assertArrayHasKey('stage', $golden);
        $this->assertEquals('identity', $golden['stage']);
        $this->assertArrayHasKey('exam_id', $golden);
        $this->assertArrayHasKey('identity_data', $golden);
    }

    /**
     * Test Identity has confidence scores
     */
    public function test_identity_has_confidence_scores(): void
    {
        $goldenId = 'pl-b1';
        $golden = $this->loadGolden($goldenId, 'identity');

        $this->assertArrayHasKey('confidence', $golden);
        $confidence = $golden['confidence'];

        if (!empty($confidence)) {
            $this->assertIsArray($confidence);

            // If overall is present, it should be between 0 and 1
            if (isset($confidence['overall'])) {
                $this->assertIsNumeric($confidence['overall']);
                $this->assertGreaterThanOrEqual(0, $confidence['overall']);
                $this->assertLessThanOrEqual(1, $confidence['overall']);
            }
        }
    }

    /**
     * Test Identity comparison hints are defined
     */
    public function test_identity_has_comparison_hints(): void
    {
        $goldenId = 'pl-b1';
        $golden = $this->loadGolden($goldenId, 'identity');

        $this->assertArrayHasKey('_comparison_hints', $golden);

        $hints = $golden['_comparison_hints'];
        $this->assertArrayHasKey('critical_fields', $hints);
        $this->assertArrayHasKey('flexible_fields', $hints);
        $this->assertArrayHasKey('ignore_fields', $hints);
    }
}
