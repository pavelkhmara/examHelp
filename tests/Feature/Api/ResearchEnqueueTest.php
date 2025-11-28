<?php

namespace Tests\Feature\Api;

use App\Jobs\RunExamResearchJob;
use App\Models\Exam;
use App\Models\GenerationTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * @group broken
 */
class ResearchEnqueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_research_queues_job_and_returns_202(): void
    {
        Bus::fake();

        $exam = Exam::factory()->create([
            'research_status' => 'queued',
        ]);

        $res = $this->postJson("/api/exams/{$exam->id}/research", [
            'notes' => 'Chcę zdać egzamin B2',
        ])->assertStatus(202)->json();

        $this->assertArrayHasKey('task_id', $res);
        $this->assertEquals('queued', $res['status']);

        $task = GenerationTask::query()->find($res['task_id']);
        $this->assertNotNull($task);
        $this->assertEquals($exam->id, $task->exam_id);
        $this->assertEquals('queued', $task->status);
        $this->assertEquals('research', $task->type);

        Bus::assertDispatched(RunExamResearchJob::class);
    }
}
