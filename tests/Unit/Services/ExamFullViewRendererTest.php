<?php

namespace Tests\Unit\Services;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\ExamExampleQuestion;
use App\Models\Question;
use App\Services\Nova\ExamFullViewRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamFullViewRendererTest extends TestCase
{
    use RefreshDatabase;

    private ExamFullViewRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new ExamFullViewRenderer;
    }

    /** @test */
    public function it_renders_exam_header_with_metadata()
    {
        $exam = Exam::factory()->create([
            'title' => 'Test Exam',
            'level' => 'C1',
            'language_of_test' => 'pl',
            'description' => 'Test description',
        ]);

        $html = $this->renderer->renderExam($exam);

        $this->assertStringContainsString('Test Exam', $html);
        $this->assertStringContainsString('C1', $html);
        $this->assertStringContainsString('pl', $html);
        $this->assertStringContainsString('Test description', $html);
    }

    /** @test */
    public function it_renders_empty_state_when_no_structure()
    {
        $exam = Exam::factory()->create([
            'meta' => [], // No structure_v2
        ]);

        $html = $this->renderer->renderExam($exam);

        $this->assertStringContainsString('Нет структуры экзамена', $html);
    }

    /** @test */
    public function it_renders_sections_from_structure_v2()
    {
        $exam = Exam::factory()->create([
            'meta' => [
                'structure_v2' => [
                    'sections' => [
                        [
                            'id' => 'listening',
                            'title' => 'Listening Section',
                            'skill' => 'listening',
                            'duration_min' => 40,
                            'max_score' => 40,
                            'assembly' => ['mode' => 'inline'],
                        ],
                    ],
                ],
            ],
        ]);

        $html = $this->renderer->renderExam($exam);

        $this->assertStringContainsString('Listening Section', $html);
        $this->assertStringContainsString('listening', $html);
        $this->assertStringContainsString('40 min', $html);
        $this->assertStringContainsString('40 pts', $html);
    }

    /** @test */
    public function it_renders_inline_mode_with_all_questions()
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

        $html = $this->renderer->renderExam($exam);

        // Should show all 3 questions
        $this->assertStringContainsString('3 сгенерированных заданий', $html);
        $this->assertStringContainsString('Задание 1', $html);
        $this->assertStringContainsString('Задание 2', $html);
        $this->assertStringContainsString('Задание 3', $html);
    }

    /** @test */
    public function it_renders_blueprint_mode_with_expected_count_notice()
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
                            'expected_task_count' => ['target' => 10],
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

        $html = $this->renderer->renderExam($exam);

        $this->assertStringContainsString('Blueprint Mode', $html);
        $this->assertStringContainsString('10 заданий', $html);
        $this->assertStringContainsString('(шаблон)', $html);
    }

    /** @test */
    public function it_renders_pool_mode_with_selection_notice()
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
                            'expected_task_count' => ['target' => 5],
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

        $html = $this->renderer->renderExam($exam);

        $this->assertStringContainsString('Pool Mode', $html);
        $this->assertStringContainsString('10 сгенерированных вопросов', $html); // pool size
        $this->assertStringContainsString('5', $html); // selected count
    }

    /** @test */
    public function it_renders_generated_question_with_instructions()
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
            'instructions' => [
                'brief' => 'Read the text and answer the question',
            ],
        ]);

        $html = $this->renderer->renderExam($exam);

        $this->assertStringContainsString('Read the text and answer the question', $html);
    }

    /** @test */
    public function it_renders_question_options_with_correct_answer_marked()
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
            'interaction' => [
                'response_type' => 'selection',
                'options' => [
                    ['id' => 'A', 'label' => 'Option A'],
                    ['id' => 'B', 'label' => 'Option B'],
                    ['id' => 'C', 'label' => 'Option C'],
                ],
            ],
            'scoring' => [
                'answer_key' => 'B',
            ],
        ]);

        $html = $this->renderer->renderExam($exam);

        $this->assertStringContainsString('Option A', $html);
        $this->assertStringContainsString('Option B', $html);
        $this->assertStringContainsString('Option C', $html);
        // Correct answer should have green checkmark
        $this->assertStringContainsString('✓', $html);
    }

    /** @test */
    public function it_renders_empty_state_when_no_questions()
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

        $html = $this->renderer->renderExam($exam);

        $this->assertStringContainsString('Нет вопросов для отображения', $html);
    }

    /** @test */
    public function it_correctly_pluralizes_russian_words()
    {
        $renderer = new ExamFullViewRenderer;

        // Use reflection to test private method
        $reflection = new \ReflectionClass($renderer);
        $method = $reflection->getMethod('pluralize');
        $method->setAccessible(true);

        // Test pluralization
        $this->assertEquals('задание', $method->invoke($renderer, 1, 'задание', 'задания', 'заданий'));
        $this->assertEquals('задания', $method->invoke($renderer, 2, 'задание', 'задания', 'заданий'));
        $this->assertEquals('задания', $method->invoke($renderer, 3, 'задание', 'задания', 'заданий'));
        $this->assertEquals('заданий', $method->invoke($renderer, 5, 'задание', 'задания', 'заданий'));
        $this->assertEquals('заданий', $method->invoke($renderer, 10, 'задание', 'задания', 'заданий'));
        $this->assertEquals('задание', $method->invoke($renderer, 21, 'задание', 'задания', 'заданий'));
        $this->assertEquals('задания', $method->invoke($renderer, 22, 'задание', 'задания', 'заданий'));
    }

    /** @test */
    public function it_escapes_html_in_user_content()
    {
        $exam = Exam::factory()->create([
            'title' => '<script>alert("XSS")</script>',
            'description' => '<img src=x onerror=alert("XSS")>',
            'meta' => [
                'structure_v2' => [
                    'sections' => [],
                ],
            ],
        ]);

        $html = $this->renderer->renderExam($exam);

        // Should not contain unescaped HTML tags
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        // Should contain escaped versions
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x', $html);
    }
}
