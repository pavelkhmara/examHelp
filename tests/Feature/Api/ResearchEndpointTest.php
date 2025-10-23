<?php

namespace Tests\Feature\Api;

use App\Jobs\RunExamResearchJob;
use App\Models\Exam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResearchEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_research_task_and_returns_task_id(): void
    {
        // Отключаем Authenticate на время теста, чтобы не ловить 401
        $this->withoutMiddleware(\Illuminate\Auth\Middleware\Authenticate::class);

        Queue::fake();

        $exam = Exam::query()->create([
            'id' => (string) Str::uuid(),
            'slug' => 'test-exam',
            'title' => 'Test Exam',
            'level' => 'B1',
            'is_active' => true,
        ]);

        $res = $this->postJson("/api/exams/{$exam->id}/research", [
            'notes' => 'Chcę zdać egzamin B2',
        ]);

        $res->assertStatus(202)->assertJsonStructure(['task_id', 'status']);

        Queue::assertPushed(RunExamResearchJob::class);
    }
}
