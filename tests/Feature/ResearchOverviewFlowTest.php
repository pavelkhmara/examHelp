<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\ExamResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;


class ResearchOverviewFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_overview_success_is_saved_into_task_result(): void
    {
        $exam = Exam::factory()->create();

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type'    => 'research',
            'status'  => 'running',
        ]);

        /** @var ExamResearchService $svc */
        $svc = app(ExamResearchService::class);
        $res = $svc->runPipeline($exam, $task);

        $this->assertTrue($res['ok'] ?? false);
        $this->assertDatabaseHas('generation_tasks', [
            'id' => $task->id,
            'status' => 'completed',
        ]);
        $task->refresh();
        $this->assertIsArray($task->result);
        // $this->assertArrayHasKey('sections', $task->result);
        // $this->assertArrayHasKey('total_score', $task->result);
    }
}
