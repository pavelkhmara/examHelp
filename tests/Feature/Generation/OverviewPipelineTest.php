<?php

namespace Tests\Feature\Generation;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\GenerationTask;
use App\Services\LanguageApp\ExamResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @group broken
 */
class OverviewPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set mock AI provider for tests
        config(['ai.provider' => 'mock']);
    }

    public function test_phase_a_generates_valid_skeleton()
    {
        // Arrange
        $exam = Exam::factory()->create([
            'title' => 'IELTS Academic Test',
            'level' => 'B2',
            'language_of_test' => 'en',
        ]);

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'running',
            'request' => [],
        ]);

        $service = app(ExamResearchService::class);

        // Act
        $skeleton = $service->runPhaseA($exam, $task);

        // Assert
        $this->assertNotNull($skeleton);
        $this->assertArrayHasKey('sections', $skeleton);
        $this->assertGreaterThan(0, count($skeleton['sections']));

        // Verify skeleton structure
        foreach ($skeleton['sections'] as $section) {
            $this->assertArrayHasKey('id', $section);
            $this->assertArrayHasKey('skill', $section);
            $this->assertArrayHasKey('duration_min', $section);
            $this->assertArrayHasKey('max_score', $section);
        }

        // Verify saved to exam.meta
        $exam->refresh();
        $this->assertNotNull($exam->meta['structure_v2']);
        $this->assertEquals($skeleton, $exam->meta['structure_v2']);
    }

    public function test_phase_b_adds_assembly_config()
    {
        // Arrange
        $exam = Exam::factory()->create([
            'title' => 'IELTS Academic Test',
            'level' => 'B2',
            'language_of_test' => 'en',
        ]);

        // Create skeleton structure (Phase A result)
        $skeleton = [
            'id' => 'ielts-academic',
            'version' => '2.0',
            'meta' => ['name' => 'IELTS Academic', 'language' => 'en', 'level' => 'B2'],
            'pass_policy' => ['mode' => 'overall_only', 'overall_threshold_percent' => 60],
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

        $exam->meta = ['structure_v2' => $skeleton];
        $exam->save();

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'running',
            'request' => [],
        ]);

        $service = app(ExamResearchService::class);

        // Act
        $result = $service->runPhaseB($exam, $task);

        // Assert
        $this->assertNotNull($result);
        $exam->refresh();
        $structure = $exam->meta['structure_v2'];

        // Verify assembly config added to sections
        $this->assertArrayHasKey('assembly', $structure['sections'][0]);
        $assembly = $structure['sections'][0]['assembly'];

        $this->assertArrayHasKey('mode', $assembly);
        $this->assertContains($assembly['mode'], ['pool', 'blueprint', 'inline']);

        // Verify tasks array exists
        $this->assertArrayHasKey('tasks', $structure['sections'][0]);
        $this->assertIsArray($structure['sections'][0]['tasks']);
    }

    public function test_phase_a_creates_exam_categories()
    {
        // Arrange
        $exam = Exam::factory()->create([
            'title' => 'Test Exam',
            'level' => 'B2',
            'language_of_test' => 'en',
        ]);

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'running',
            'request' => [],
        ]);

        $service = app(ExamResearchService::class);

        // Act
        $skeleton = $service->runPhaseA($exam, $task);

        // Assert
        $exam->refresh();
        $categories = ExamCategory::where('exam_id', $exam->id)->get();

        $this->assertGreaterThan(0, $categories->count());

        // Verify each section has a corresponding ExamCategory
        foreach ($skeleton['sections'] as $section) {
            $category = $categories->firstWhere('name', $section['id']);
            $this->assertNotNull($category, "ExamCategory not found for section: {$section['id']}");
        }
    }

    public function test_phase_a_validates_skeleton_structure()
    {
        // Arrange
        $exam = Exam::factory()->create([
            'title' => 'Test Exam',
            'level' => 'B2',
            'language_of_test' => 'en',
        ]);

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'running',
            'request' => [],
        ]);

        $service = app(ExamResearchService::class);

        // Act
        $skeleton = $service->runPhaseA($exam, $task);

        // Assert - verify JSON schema compliance
        $this->assertArrayHasKey('id', $skeleton);
        $this->assertArrayHasKey('version', $skeleton);
        $this->assertArrayHasKey('meta', $skeleton);
        $this->assertArrayHasKey('pass_policy', $skeleton);
        $this->assertArrayHasKey('sections', $skeleton);

        // Verify meta structure
        $this->assertArrayHasKey('name', $skeleton['meta']);
        $this->assertArrayHasKey('language', $skeleton['meta']);
        $this->assertArrayHasKey('level', $skeleton['meta']);

        // Verify pass_policy structure
        $this->assertArrayHasKey('mode', $skeleton['pass_policy']);
        $this->assertArrayHasKey('overall_threshold_percent', $skeleton['pass_policy']);
    }
}
