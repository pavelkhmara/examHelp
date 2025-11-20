<?php

namespace App\Jobs;

use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\OverviewStructureBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generate assembly plan for a single exam section (Phase B for one section)
 *
 * This job runs in parallel with other section assembly jobs to speed up structure generation.
 * Each job generates the assembly{} configuration for its section.
 */
class GenerateSectionAssemblyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600; // 10 minutes for AI-heavy task

    public function __construct(
        public int $taskId,
        public string $sectionId,
        public array $sectionSkeleton
    ) {}

    public function handle(OverviewStructureBuilder $builder): void
    {
        $task = GenerationTask::query()->findOrFail($this->taskId);
        /** @var Exam $exam */
        $exam = Exam::query()->findOrFail($task->exam_id);

        Log::info('GenerateSectionAssemblyJob started', [
            'task_id' => $task->id,
            'exam_id' => $exam->id,
            'section_id' => $this->sectionId,
        ]);

        $task->addActivity('section_assembly_started', "Generating assembly plan for section: {$this->sectionId}");

        try {
            // Generate assembly plan for this section
            $sectionWithAssembly = $builder->generateSectionAssembly($exam, $task, $this->sectionSkeleton);

            Log::debug('Section assembly generated', [
                'section_id' => $this->sectionId,
                'has_assembly' => isset($sectionWithAssembly['assembly']),
            ]);

            // Atomic update: add/update section in exam.meta.structure_v2.sections
            DB::transaction(function () use ($exam, $sectionWithAssembly) {
                $exam = Exam::query()->lockForUpdate()->findOrFail($exam->id);

                $structure = $exam->meta['structure_v2'] ?? [];
                $sections = $structure['sections'] ?? [];

                // Find and update existing section, or append new one
                $updated = false;
                foreach ($sections as $idx => $section) {
                    if ($section['id'] === $this->sectionId) {
                        $sections[$idx] = $sectionWithAssembly;
                        $updated = true;
                        break;
                    }
                }

                if (!$updated) {
                    $sections[] = $sectionWithAssembly;
                }

                $structure['sections'] = $sections;
                $exam->meta = array_merge($exam->meta ?? [], ['structure_v2' => $structure]);
                $exam->save();

                Log::info('Section assembly saved to exam.meta.structure_v2', [
                    'exam_id' => $exam->id,
                    'section_id' => $this->sectionId,
                    'total_sections' => count($sections),
                ]);
            });

            // Mark section as completed in task.request.sections_status
            $task->refresh();
            $request = $task->request ?? [];
            $sectionsStatus = $request['sections_status'] ?? [];
            $sectionsStatus[$this->sectionId] = 'completed';
            $request['sections_status'] = $sectionsStatus;
            $task->request = $request;
            $task->save();

            $task->addActivity('section_assembly_completed', "Assembly plan for {$this->sectionId} completed");

            Log::info('GenerateSectionAssemblyJob completed', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
                'section_id' => $this->sectionId,
            ]);
        } catch (\Throwable $e) {
            Log::error('GenerateSectionAssemblyJob failed', [
                'task_id' => $task->id,
                'exam_id' => $exam->id,
                'section_id' => $this->sectionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark section as failed
            $task->refresh();
            $request = $task->request ?? [];
            $sectionsStatus = $request['sections_status'] ?? [];
            $sectionsStatus[$this->sectionId] = 'failed';
            $request['sections_status'] = $sectionsStatus;
            $task->request = $request;
            $task->save();

            $task->addActivity('section_assembly_failed', "Assembly plan for {$this->sectionId} failed: {$e->getMessage()}");

            throw $e;
        }
    }
}
