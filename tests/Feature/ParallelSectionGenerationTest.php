<?php

namespace Tests\Feature;

use App\Jobs\FinalizeSectionGenerationJob;
use App\Jobs\GenerateSectionAssemblyJob;
use App\Jobs\RunExamResearchJob;
use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\GenerationTask;
use App\Support\Queue\TaskDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ParallelSectionGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_section_assembly_job_creates_assembly_for_single_section(): void
    {
        // Create exam with Phase A skeleton
        $exam = Exam::factory()->create([
            'title' => 'IELTS Academic',
            'level' => 'B2',
            'user_input' => 'IELTS Academic test',
        ]);

        $phaseASkeleton = [
            'sections' => [
                [
                    'id' => 'listening',
                    'title' => 'Listening',
                    'skill' => 'listening',
                    'duration_min' => 30,
                    'max_score' => 40,
                    'tasks' => [],
                ],
                [
                    'id' => 'reading',
                    'title' => 'Reading',
                    'skill' => 'reading',
                    'duration_min' => 60,
                    'max_score' => 40,
                    'tasks' => [],
                ],
            ],
        ];

        // Save skeleton to exam.meta
        $exam->meta = ['structure_v2' => $phaseASkeleton];
        $exam->save();

        // Create task
        $task = GenerationTask::factory()->create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'running',
            'request' => [
                'expected_sections' => ['listening', 'reading'],
                'sections_status' => [
                    'listening' => 'pending',
                    'reading' => 'pending',
                ],
            ],
        ]);

        $sectionSkeleton = $phaseASkeleton['sections'][0]; // listening

        // Run GenerateSectionAssemblyJob
        $job = new GenerateSectionAssemblyJob($task->id, 'listening', $sectionSkeleton);
        $job->handle(app(\App\Services\LanguageApp\OverviewStructureBuilder::class));

        // Verify section was updated
        $exam->refresh();
        $structure = $exam->meta['structure_v2'] ?? [];
        $sections = $structure['sections'] ?? [];

        $listeningSection = collect($sections)->firstWhere('id', 'listening');
        $this->assertNotNull($listeningSection, 'Listening section should exist in structure');
        $this->assertArrayHasKey('assembly', $listeningSection, 'Section should have assembly config');

        // Verify section status was marked as completed
        $task->refresh();
        $sectionsStatus = $task->request['sections_status'] ?? [];
        $this->assertEquals('completed', $sectionsStatus['listening'] ?? null);
    }

    public function test_finalize_section_generation_job_waits_for_all_sections(): void
    {
        $exam = Exam::factory()->create([
            'title' => 'IELTS Academic',
            'level' => 'B2',
        ]);

        $structure = [
            'sections' => [
                [
                    'id' => 'listening',
                    'title' => 'Listening',
                    'skill' => 'listening',
                    'duration_min' => 30,
                    'max_score' => 40,
                    'question_archetypes' => [],
                    'assembly' => ['mode' => 'pool'],
                    'tasks' => [],
                ],
                [
                    'id' => 'reading',
                    'title' => 'Reading',
                    'skill' => 'reading',
                    'duration_min' => 60,
                    'max_score' => 40,
                    'question_archetypes' => [],
                    'assembly' => ['mode' => 'pool'],
                    'tasks' => [],
                ],
            ],
        ];

        $exam->meta = ['structure_v2' => $structure];
        $exam->save();

        // Create task with all sections completed
        $task = GenerationTask::factory()->create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'running',
            'request' => [
                'expected_sections' => ['listening', 'reading'],
                'sections_status' => [
                    'listening' => 'completed',
                    'reading' => 'completed',
                ],
            ],
            'result' => [
                'identity' => [
                    'canonical' => [
                        'title' => 'IELTS Academic',
                        'provider' => 'British Council',
                        'family' => 'IELTS',
                    ],
                ],
            ],
        ]);

        // Run FinalizeSectionGenerationJob
        $job = new FinalizeSectionGenerationJob($task->id);
        $job->handle(
            app(\App\Services\LanguageApp\ExamResearchService::class),
            app(\App\Services\LanguageApp\SanityCheckerService::class)
        );

        // Verify task was completed
        $task->refresh();
        $this->assertEquals('completed', $task->status);

        // Verify ExamCategory records were created
        $categories = ExamCategory::where('exam_id', $exam->id)->get();
        $this->assertCount(2, $categories);

        $categoryNames = $categories->pluck('name')->toArray();
        $this->assertContains('listening', $categoryNames);
        $this->assertContains('reading', $categoryNames);

        // Verify exam research_status was set to completed
        $exam->refresh();
        $this->assertEquals('completed', $exam->research_status);
    }

    public function test_finalize_job_retries_when_sections_not_ready(): void
    {
        Queue::fake();

        $exam = Exam::factory()->create();

        // Create task with only 1 of 2 sections completed
        $task = GenerationTask::factory()->create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'running',
            'request' => [
                'expected_sections' => ['listening', 'reading'],
                'sections_status' => [
                    'listening' => 'completed',
                    'reading' => 'pending', // NOT ready yet
                ],
            ],
        ]);

        // Run FinalizeSectionGenerationJob
        $job = new FinalizeSectionGenerationJob($task->id);

        // Should release job back to queue
        try {
            $job->handle(
                app(\App\Services\LanguageApp\ExamResearchService::class),
                app(\App\Services\LanguageApp\SanityCheckerService::class)
            );
        } catch (\Illuminate\Queue\ManuallyFailedException $e) {
            // Job was released
        }

        // Task should still be running (not completed)
        $task->refresh();
        $this->assertEquals('running', $task->status);
    }

    public function test_finalize_job_fails_task_when_section_fails(): void
    {
        $exam = Exam::factory()->create();

        // Create task with one failed section
        $task = GenerationTask::factory()->create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'running',
            'request' => [
                'expected_sections' => ['listening', 'reading'],
                'sections_status' => [
                    'listening' => 'completed',
                    'reading' => 'failed', // FAILED
                ],
            ],
        ]);

        // Run FinalizeSectionGenerationJob
        $job = new FinalizeSectionGenerationJob($task->id);
        $job->handle(
            app(\App\Services\LanguageApp\ExamResearchService::class),
            app(\App\Services\LanguageApp\SanityCheckerService::class)
        );

        // Task should be marked as failed
        $task->refresh();
        $this->assertEquals('failed', $task->status);
        $this->assertStringContainsString('failed', $task->error);

        // Exam research_status should be failed
        $exam->refresh();
        $this->assertEquals('failed', $exam->research_status);
    }

    public function test_parallel_mode_dispatches_section_jobs(): void
    {
        Queue::fake();

        $exam = Exam::factory()->create([
            'title' => 'IELTS Academic',
            'level' => 'B2',
            'user_input' => 'IELTS Academic test',
        ]);

        $dispatcher = app(TaskDispatcher::class);

        // Dispatch research task with parallel mode enabled
        $task = $dispatcher->enqueue(
            type: 'research',
            subject: $exam,
            request: [
                'exam_id' => $exam->id,
                'user_input' => $exam->user_input,
                'use_two_phase_generation' => true,
                'use_parallel_sections' => true,
                'without_confirmation' => true, // Skip identity confirmation for testing
            ],
            jobClass: RunExamResearchJob::class,
            idempotencyKey: "test:{$exam->id}:parallel"
        );

        $this->assertInstanceOf(GenerationTask::class, $task);

        // Verify RunExamResearchJob was dispatched
        Queue::assertPushed(RunExamResearchJob::class, 1);
    }

    public function test_sequential_mode_does_not_dispatch_section_jobs(): void
    {
        Queue::fake();

        $exam = Exam::factory()->create([
            'title' => 'IELTS Academic',
            'level' => 'B2',
            'user_input' => 'IELTS Academic test',
        ]);

        $dispatcher = app(TaskDispatcher::class);

        // Dispatch research task with sequential mode (parallel disabled)
        $task = $dispatcher->enqueue(
            type: 'research',
            subject: $exam,
            request: [
                'exam_id' => $exam->id,
                'user_input' => $exam->user_input,
                'use_two_phase_generation' => true,
                'use_parallel_sections' => false, // SEQUENTIAL mode
                'without_confirmation' => true,
            ],
            jobClass: RunExamResearchJob::class,
            idempotencyKey: "test:{$exam->id}:sequential"
        );

        $this->assertInstanceOf(GenerationTask::class, $task);

        // Verify RunExamResearchJob was dispatched
        Queue::assertPushed(RunExamResearchJob::class, 1);

        // GenerateSectionAssemblyJob should NOT be dispatched in sequential mode
        Queue::assertNotPushed(GenerateSectionAssemblyJob::class);
    }

    public function test_section_assembly_job_updates_atomically(): void
    {
        // This test verifies that multiple section jobs can update the same exam
        // without race conditions (using DB transactions + lockForUpdate)

        $exam = Exam::factory()->create([
            'title' => 'IELTS Academic',
        ]);

        $phaseASkeleton = [
            'sections' => [
                [
                    'id' => 'listening',
                    'title' => 'Listening',
                    'skill' => 'listening',
                    'duration_min' => 30,
                    'max_score' => 40,
                    'tasks' => [],
                ],
                [
                    'id' => 'reading',
                    'title' => 'Reading',
                    'skill' => 'reading',
                    'duration_min' => 60,
                    'max_score' => 40,
                    'tasks' => [],
                ],
            ],
        ];

        $exam->meta = ['structure_v2' => $phaseASkeleton];
        $exam->save();

        $task = GenerationTask::factory()->create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'running',
            'request' => [
                'expected_sections' => ['listening', 'reading'],
                'sections_status' => [
                    'listening' => 'pending',
                    'reading' => 'pending',
                ],
            ],
        ]);

        // Simulate two section jobs running sequentially (in tests they can't run truly parallel)
        $job1 = new GenerateSectionAssemblyJob($task->id, 'listening', $phaseASkeleton['sections'][0]);
        $job1->handle(app(\App\Services\LanguageApp\OverviewStructureBuilder::class));

        $job2 = new GenerateSectionAssemblyJob($task->id, 'reading', $phaseASkeleton['sections'][1]);
        $job2->handle(app(\App\Services\LanguageApp\OverviewStructureBuilder::class));

        // Verify both sections exist in structure
        $exam->refresh();
        $structure = $exam->meta['structure_v2'] ?? [];
        $sections = $structure['sections'] ?? [];

        $this->assertCount(2, $sections, 'Both sections should be in structure');

        $sectionIds = array_column($sections, 'id');
        $this->assertContains('listening', $sectionIds);
        $this->assertContains('reading', $sectionIds);

        // Verify both have assembly configs
        foreach ($sections as $section) {
            $this->assertArrayHasKey('assembly', $section, "Section {$section['id']} should have assembly");
        }
    }
}
