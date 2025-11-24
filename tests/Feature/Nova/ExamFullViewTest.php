<?php

namespace Tests\Feature\Nova;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Nova\Nova;
use Tests\TestCase;

class ExamFullViewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create admin user for Nova access
        $this->user = User::factory()->create([
            'email' => 'admin@example.com',
        ]);

        // Bypass Nova gate for testing
        \Illuminate\Support\Facades\Gate::define('viewNova', fn () => true);

        $this->actingAs($this->user);
    }

    /** @test */
    public function exam_full_view_page_loads_successfully()
    {
        $exam = Exam::factory()->create([
            'title' => 'Test Exam',
            'meta' => [
                'structure_v2' => [
                    'sections' => [],
                ],
            ],
        ]);

        $response = $this->get("/nova/resources/exam-full-view/{$exam->id}");

        $response->assertStatus(200);
    }

    /** @test */
    public function exam_full_view_link_appears_on_exam_detail_page()
    {
        $exam = Exam::factory()->create([
            'title' => 'Test Exam',
        ]);

        $response = $this->get("/nova/resources/exams/{$exam->id}");

        $response->assertStatus(200);
        $response->assertSee('View Full Exam Preview');
        $response->assertSee("/nova/resources/exam-full-view/{$exam->id}");
    }

    /** @test */
    public function exam_full_view_is_read_only()
    {
        $exam = Exam::factory()->create();

        // Try to access create form
        $response = $this->get('/nova/resources/exam-full-view/new');
        $response->assertStatus(404); // Should not exist

        // Try to access edit form
        $response = $this->get("/nova/resources/exam-full-view/{$exam->id}/edit");
        $response->assertStatus(404); // Should not exist
    }

    /** @test */
    public function exam_full_view_is_hidden_from_navigation()
    {
        $response = $this->get('/nova-api/menus');

        $response->assertStatus(200);
        $content = $response->getContent();

        // Should not contain ExamFullView in menu
        $this->assertStringNotContainsString('exam-full-view', $content);
    }

    /** @test */
    public function exam_full_view_displays_exam_title()
    {
        $exam = Exam::factory()->create([
            'title' => 'C1 Polish Exam',
            'meta' => [
                'structure_v2' => [
                    'sections' => [],
                ],
            ],
        ]);

        $response = $this->get("/nova/resources/exam-full-view/{$exam->id}");

        $response->assertStatus(200);
        $response->assertSee('C1 Polish Exam');
    }

    /** @test */
    public function exam_full_view_displays_all_sections()
    {
        $exam = Exam::factory()->create([
            'meta' => [
                'structure_v2' => [
                    'sections' => [
                        [
                            'id' => 'listening',
                            'title' => 'Listening Section',
                            'skill' => 'listening',
                            'assembly' => ['mode' => 'inline'],
                        ],
                        [
                            'id' => 'reading',
                            'title' => 'Reading Section',
                            'skill' => 'reading',
                            'assembly' => ['mode' => 'inline'],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->get("/nova/resources/exam-full-view/{$exam->id}");

        $response->assertStatus(200);
        $response->assertSee('Listening Section');
        $response->assertSee('Reading Section');
    }

    /** @test */
    public function exam_full_view_displays_generated_questions()
    {
        $exam = Exam::factory()->create([
            'meta' => [
                'structure_v2' => [
                    'sections' => [
                        [
                            'id' => 'reading',
                            'title' => 'Reading',
                            'skill' => 'reading',
                            'assembly' => ['mode' => 'inline'],
                        ],
                    ],
                ],
            ],
        ]);

        $category = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'key' => 'reading',
        ]);

        Question::factory()->create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'type' => 'single_select',
            'instructions' => [
                'brief' => 'Choose the correct answer',
            ],
        ]);

        $response = $this->get("/nova/resources/exam-full-view/{$exam->id}");

        $response->assertStatus(200);
        $response->assertSee('Сгенерированные вопросы');
        $response->assertSee('Choose the correct answer');
    }

    /** @test */
    public function exam_full_view_shows_inline_assembly_mode()
    {
        $exam = Exam::factory()->create([
            'meta' => [
                'structure_v2' => [
                    'sections' => [
                        [
                            'id' => 'reading',
                            'title' => 'Reading',
                            'skill' => 'reading',
                            'assembly' => ['mode' => 'inline'],
                        ],
                    ],
                ],
            ],
        ]);

        $category = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'key' => 'reading',
        ]);

        Question::factory()->count(3)->create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
        ]);

        $response = $this->get("/nova/resources/exam-full-view/{$exam->id}");

        $response->assertStatus(200);
        // Should show all 3 questions (inline mode shows all)
        $response->assertSee('3 задания');
    }

    /** @test */
    public function exam_full_view_shows_blueprint_assembly_mode_notice()
    {
        $exam = Exam::factory()->create([
            'meta' => [
                'structure_v2' => [
                    'sections' => [
                        [
                            'id' => 'reading',
                            'title' => 'Reading',
                            'skill' => 'reading',
                            'assembly' => ['mode' => 'blueprint'],
                            'expected_question_count' => ['target' => 10],
                        ],
                    ],
                ],
            ],
        ]);

        $category = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'key' => 'reading',
        ]);

        Question::factory()->count(2)->create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
        ]);

        $response = $this->get("/nova/resources/exam-full-view/{$exam->id}");

        $response->assertStatus(200);
        $response->assertSee('Blueprint Mode');
        $response->assertSee('10 заданий');
        $response->assertSee('(шаблон)');
    }

    /** @test */
    public function exam_full_view_shows_pool_assembly_mode_notice()
    {
        $exam = Exam::factory()->create([
            'meta' => [
                'structure_v2' => [
                    'sections' => [
                        [
                            'id' => 'reading',
                            'title' => 'Reading',
                            'skill' => 'reading',
                            'assembly' => ['mode' => 'pool'],
                            'expected_question_count' => ['target' => 5],
                        ],
                    ],
                ],
            ],
        ]);

        $category = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'key' => 'reading',
        ]);

        Question::factory()->count(10)->create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
        ]);

        $response = $this->get("/nova/resources/exam-full-view/{$exam->id}");

        $response->assertStatus(200);
        $response->assertSee('Pool Mode');
        $response->assertSee('10 заданий'); // pool size
    }

    /** @test */
    public function exam_full_view_shows_empty_state_when_no_structure()
    {
        $exam = Exam::factory()->create([
            'meta' => [], // No structure
        ]);

        $response = $this->get("/nova/resources/exam-full-view/{$exam->id}");

        $response->assertStatus(200);
        $response->assertSee('Нет структуры экзамена');
    }

    /** @test */
    public function exam_full_view_shows_empty_state_when_no_questions()
    {
        $exam = Exam::factory()->create([
            'meta' => [
                'structure_v2' => [
                    'sections' => [
                        [
                            'id' => 'reading',
                            'title' => 'Reading',
                            'skill' => 'reading',
                            'assembly' => ['mode' => 'inline'],
                        ],
                    ],
                ],
            ],
        ]);

        ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'key' => 'reading',
        ]);

        $response = $this->get("/nova/resources/exam-full-view/{$exam->id}");

        $response->assertStatus(200);
        $response->assertSee('Нет вопросов для отображения');
    }

    /** @test */
    public function exam_full_view_requires_authentication()
    {
        $this->app['auth']->forgetGuards();
        auth()->logout();

        $exam = Exam::factory()->create();

        $response = $this->get("/nova/resources/exam-full-view/{$exam->id}");

        // Should redirect to login
        $response->assertRedirect('/nova/login');
    }
}
