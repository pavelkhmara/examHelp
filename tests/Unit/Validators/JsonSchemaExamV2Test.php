<?php

namespace Tests\Unit\Validators;

use App\Services\LanguageApp\Validators\JsonSchemaExamV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JsonSchemaExamV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_valid_exam_skeleton_passes_validation(): void
    {
        $validator = new JsonSchemaExamV2;

        $data = [
            'id' => 'test-exam',
            'version' => '2.0',
            'meta' => [
                'name' => 'Test Exam',
                'language' => 'en',
                'level' => 'B2',
            ],
            'pass_policy' => [
                'mode' => 'overall_only',
                'overall_threshold_percent' => 60,
            ],
            'policies' => [],
            'sections' => [
                [
                    'id' => 'reading',
                    'skill' => 'reading',
                    'duration_min' => 60,
                    'max_score' => 40,
                    'min_pass_percent' => 60,
                ],
            ],
        ];

        $result = $validator->validate($data);

        $this->assertIsArray($result);
        $this->assertSame('reading', $result['sections'][0]['id']);
    }

    public function test_duplicate_section_ids_are_rejected(): void
    {
        $this->expectException(\Throwable::class);

        $validator = new JsonSchemaExamV2;

        $data = [
            'id' => 'test-exam',
            'version' => '2.0',
            'meta' => ['name' => 'Test', 'language' => 'en', 'level' => 'B2'],
            'pass_policy' => ['mode' => 'overall_only', 'overall_threshold_percent' => 60],
            'policies' => [],
            'sections' => [
                ['id' => 'reading', 'skill' => 'reading', 'duration_min' => 60, 'max_score' => 40, 'min_pass_percent' => 60],
                ['id' => 'reading', 'skill' => 'reading', 'duration_min' => 60, 'max_score' => 40, 'min_pass_percent' => 60],
            ],
        ];

        $validator->validate($data);
    }
}
