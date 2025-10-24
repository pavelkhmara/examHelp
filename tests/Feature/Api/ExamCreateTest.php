<?php

namespace Tests\Feature\Api;

use App\Models\Exam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamCreateTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_create_exam_requires_authentication(): void
    {
        $response = $this->postJson('/api/exams', [
            'title' => 'IELTS Academic',
            'level' => 'B2',
        ]);

        $response->assertUnauthorized();
    }

    public function test_create_exam_with_minimal_data(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/exams', [
                'title' => 'IELTS Academic',
                'level' => 'B2',
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'slug',
                    'title',
                    'description',
                    'level',
                    'is_active',
                    'research_status',
                    'created_at',
                ],
            ])
            ->assertJson([
                'data' => [
                    'title' => 'IELTS Academic',
                    'level' => 'B2',
                    'slug' => 'ielts-academic',
                    'is_active' => true,
                    'research_status' => 'queued',
                ],
            ]);

        $this->assertDatabaseHas('exams', [
            'title' => 'IELTS Academic',
            'level' => 'B2',
            'slug' => 'ielts-academic',
            'is_active' => true,
            'research_status' => 'queued',
        ]);
    }

    public function test_create_exam_with_full_data(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/exams', [
                'title' => 'TOEFL iBT',
                'description' => 'Test of English as a Foreign Language Internet-Based Test',
                'level' => 'C1',
                'slug' => 'toefl-ibt',
                'is_active' => false,
                'sources' => [
                    ['url' => 'https://www.ets.org/toefl', 'title' => 'Official TOEFL'],
                ],
                'meta' => [
                    'duration' => 180,
                    'sections' => ['reading', 'listening', 'speaking', 'writing'],
                ],
            ]);

        $response->assertCreated()
            ->assertJson([
                'data' => [
                    'title' => 'TOEFL iBT',
                    'description' => 'Test of English as a Foreign Language Internet-Based Test',
                    'level' => 'C1',
                    'slug' => 'toefl-ibt',
                    'is_active' => false,
                    'research_status' => 'queued',
                ],
            ]);

        $exam = Exam::where('slug', 'toefl-ibt')->first();
        $this->assertNotNull($exam);
        $this->assertEquals('TOEFL iBT', $exam->title);
        $this->assertEquals('C1', $exam->level);
        $this->assertFalse($exam->is_active);
        $this->assertIsArray($exam->sources);
        $this->assertCount(1, $exam->sources);
        $this->assertIsArray($exam->meta);
        $this->assertEquals(180, $exam->meta['duration']);
    }

    public function test_create_exam_auto_generates_slug(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/exams', [
                'title' => 'Goethe-Zertifikat B1',
                'level' => 'B1',
            ]);

        $response->assertCreated()
            ->assertJson([
                'data' => [
                    'slug' => 'goethe-zertifikat-b1',
                ],
            ]);
    }

    public function test_create_exam_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/exams', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'level']);
    }

    public function test_create_exam_validates_level_enum(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/exams', [
                'title' => 'Test Exam',
                'level' => 'D1', // Невалидный уровень
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['level']);
    }

    public function test_create_exam_validates_unique_slug(): void
    {
        Exam::factory()->create(['slug' => 'existing-exam']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/exams', [
                'title' => 'Test Exam',
                'level' => 'B2',
                'slug' => 'existing-exam',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_created_exam_has_uuid(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/exams', [
                'title' => 'IELTS Academic',
                'level' => 'B2',
            ]);

        $response->assertCreated();

        $id = $response->json('data.id');
        $this->assertIsString($id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $id
        );
    }
}
