<?php

namespace Tests\Feature\Api;

use App\Models\Exam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdentityGuardFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_research_endpoint_runs_identity_guard_with_full_user_input(): void
    {
        $exam = Exam::factory()->create([
            'title' => 'IELTS Academic',
            'level' => 'B2',
        ]);

        $userInput = [
            'language' => 'English',
            'where' => [
                'country' => 'PL',
                'city' => 'Warsaw',
                'modality' => 'test_center',
            ],
            'target' => [
                'level' => 'B2',
                'score' => '6.5',
            ],
            'exam_name' => 'IELTS Academic',
            'notes' => 'Preparing for academic IELTS exam',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/exams/{$exam->id}/research", [
                'user_input' => json_encode($userInput),
            ]);

        $response->assertAccepted()
            ->assertJsonStructure(['task_id']);

        $taskId = $response->json('task_id');
        $this->assertIsInt($taskId);

        // Verify task was created
        $this->assertDatabaseHas('generation_tasks', [
            'id' => $taskId,
            'exam_id' => $exam->id,
            'type' => 'research',
        ]);

        // Wait a bit for queue to process (in testing, QUEUE_CONNECTION=sync so it runs immediately)
        sleep(1);

        // Verify identity stage was logged
        $this->assertDatabaseHas('generation_logs', [
            'exam_id' => $exam->id,
            'generation_task_id' => $taskId,
            'stage' => 'identity',
        ]);
    }

    public function test_research_with_incomplete_user_input_returns_uncertain(): void
    {
        $exam = Exam::factory()->create([
            'title' => 'Unknown Exam',
            'level' => 'B1',
        ]);

        // Incomplete input - missing required fields
        $userInput = [
            'exam_name' => 'Some Exam',
            // Missing language, where, target
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/exams/{$exam->id}/research", [
                'user_input' => json_encode($userInput),
            ]);

        $response->assertAccepted();

        $taskId = $response->json('task_id');

        // Wait for processing
        sleep(1);

        // Check task result has identity with uncertain status
        $task = \App\Models\GenerationTask::find($taskId);
        $this->assertNotNull($task);

        $identity = $task->result['identity'] ?? null;
        $this->assertNotNull($identity, 'Identity result should exist');

        // Should be uncertain due to missing fields
        $this->assertEquals('uncertain', $identity['status']);
        $this->assertLessThan(0.80, $identity['confidence']);
        $this->assertNotEmpty($identity['need_fields'] ?? $identity['followups']);
    }

    public function test_confirm_identity_endpoint_removes_hold_and_continues_pipeline(): void
    {
        $exam = Exam::factory()->create([
            'title' => 'IELTS Academic',
            'level' => 'B2',
        ]);

        // Create a task with identity hold
        $task = \App\Models\GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'pending_confirmation',
            'request' => [
                'user_input' => [
                    'language' => 'English',
                    'exam_name' => 'IELTS Academic',
                ],
            ],
            'result' => [
                'identity' => [
                    'status' => 'certain',
                    'confidence' => 0.92,
                    'canonical' => [
                        'family' => 'IELTS',
                        'name' => 'IELTS Academic',
                        'provider' => 'Cambridge/IDP/British Council',
                        'variant' => 'Academic',
                        'language_of_test' => 'English',
                    ],
                    'candidates' => [],
                    'followups' => [],
                    'need_fields' => [],
                    'anchors' => [],
                    'hold' => true,
                ],
            ],
        ]);

        // Confirm the identity
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/exams/{$exam->id}/research/{$task->id}/confirm-identity", [
                'confirmed' => true,
                'notes' => 'Yes, this is correct',
            ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'confirmed',
                'continue' => true,
            ]);

        // Verify hold was removed
        $task->refresh();
        $identity = $task->result['identity'];

        $this->assertFalse($identity['hold']);
        $this->assertTrue($identity['user_confirmed']);
        $this->assertEquals('Yes, this is correct', $identity['confirmation_notes']);
    }

    public function test_reject_identity_keeps_hold_and_requests_clarification(): void
    {
        $exam = Exam::factory()->create([
            'title' => 'IELTS Academic',
            'level' => 'B2',
        ]);

        // Create a task with identity hold
        $task = \App\Models\GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'pending_confirmation',
            'request' => [
                'user_input' => [
                    'language' => 'English',
                    'exam_name' => 'IELTS Academic',
                ],
            ],
            'result' => [
                'identity' => [
                    'status' => 'certain',
                    'confidence' => 0.92,
                    'canonical' => [
                        'family' => 'IELTS',
                        'name' => 'IELTS Academic',
                        'provider' => 'Cambridge/IDP/British Council',
                        'variant' => null,
                        'language_of_test' => 'English',
                    ],
                    'candidates' => [],
                    'followups' => [],
                    'need_fields' => [],
                    'anchors' => [],
                    'hold' => true,
                ],
            ],
        ]);

        // Reject the identity
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/exams/{$exam->id}/research/{$task->id}/confirm-identity", [
                'confirmed' => false,
                'notes' => 'This is actually TOEFL',
            ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'rejected',
            ])
            ->assertJsonStructure([
                'followups',
                'task_id',
            ]);

        // Verify hold is still true and marked as rejected
        $task->refresh();
        $identity = $task->result['identity'];

        $this->assertTrue($identity['hold']);
        $this->assertTrue($identity['user_rejected']);
        $this->assertEquals('uncertain', $identity['status']);
        $this->assertEquals('This is actually TOEFL', $identity['rejection_notes']);
        $this->assertEquals('pending', $task->status);
    }

    public function test_confirm_identity_requires_authentication(): void
    {
        $exam = Exam::factory()->create();
        $task = \App\Models\GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'pending_confirmation',
            'result' => [
                'identity' => ['hold' => true],
            ],
        ]);

        $response = $this->postJson("/api/exams/{$exam->id}/research/{$task->id}/confirm-identity", [
            'confirmed' => true,
        ]);

        $response->assertUnauthorized();
    }

    public function test_confirm_identity_fails_if_no_hold(): void
    {
        $exam = Exam::factory()->create();
        $task = \App\Models\GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'completed',
            'result' => [
                'identity' => ['hold' => false],
            ],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/exams/{$exam->id}/research/{$task->id}/confirm-identity", [
                'confirmed' => true,
            ]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'No identity hold to confirm',
            ]);
    }
}
