<?php

namespace Tests\Feature\Api;

use App\Models\GenerationTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TasksStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_task_status(): void
    {
        // Отключаем Authenticate на время теста
        $this->withoutMiddleware(\Illuminate\Auth\Middleware\Authenticate::class);
        
        $task = GenerationTask::query()->create([
            'exam_id' => null,
            'type' => 'exam.research',
            'status' => 'queued',
            'request' => ['foo' => 'bar'],
            'attempts' => 0,
        ]);

        $res = $this->getJson("/api/tasks/{$task->id}");
        $res->assertOk()
            ->assertJson([
                'id' => $task->id,
                'status' => 'queued',
                'type' => 'exam.research',
            ]);
    }
}
