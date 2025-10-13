<?php

namespace Tests\Feature\Api;

use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Attempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttemptsTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_attempt(): void
    {
        $exam = Exam::factory()->create();

        $res = $this->postJson('/api/attempts', ['exam_id' => $exam->id])
            ->assertCreated()
            ->json('data');

        $this->assertTrue(isset($res['id']));
        $this->assertDatabaseHas('attempts', ['id' => $res['id'], 'exam_id' => $exam->id]);
    }

    public function test_answer_mcq_and_complete_calculates_score(): void
    {
        $exam = Exam::factory()->create();
        $attempt = Attempt::factory()->create(['exam_id' => $exam->id]);

        $q1 = Question::factory()->create(['id' => (string) Str::uuid(), 'exam_id' => $exam->id, 'type' => 'MCQ', 'position' => 1]);
        $opt1 = QuestionOption::factory()->create(['question_id' => $q1->id, 'text' => 'A', 'is_correct' => true]);
        $opt2 = QuestionOption::factory()->create(['question_id' => $q1->id, 'text' => 'B', 'is_correct' => false]);

        $q2 = Question::factory()->create(['id' => (string) Str::uuid(), 'exam_id' => $exam->id, 'type' => 'MCQ', 'position' => 2]);
        $opt3 = QuestionOption::factory()->create(['question_id' => $q2->id, 'text' => 'X', 'is_correct' => false]);
        $opt4 = QuestionOption::factory()->create(['question_id' => $q2->id, 'text' => 'Y', 'is_correct' => true]);

        // Ответы: 1 правильный, 1 неправильный => 50
        $this->postJson("/api/attempts/{$attempt->id}/answers", [
            'question_id' => $q1->id,
            'type' => 'MCQ',
            'selected_option_id' => $opt1->id,
        ])->assertOk();

        $this->postJson("/api/attempts/{$attempt->id}/answers", [
            'question_id' => $q2->id,
            'type' => 'MCQ',
            'selected_option_id' => $opt3->id,
        ])->assertOk();

        $data = $this->postJson("/api/attempts/{$attempt->id}/complete")
            ->assertOk()
            ->json('data');

        $this->assertSame(50, $data['score']);
    }

    public function test_text_answer_is_accepted_but_not_scored(): void
    {
        $exam = Exam::factory()->create();
        $attempt = Attempt::factory()->create(['exam_id' => $exam->id]);

        $qText = Question::factory()->create([
            'id' => (string) Str::uuid(),
            'exam_id' => $exam->id,
            'type' => 'TEXT',
            'prompt' => 'Introduce yourself',
            'position' => 1,
        ]);

        $this->postJson("/api/attempts/{$attempt->id}/answers", [
            'question_id' => $qText->id,
            'type' => 'TEXT',
            'text_answer' => 'Hi, I am...',
        ])->assertOk();

        $data = $this->postJson("/api/attempts/{$attempt->id}/complete")
            ->assertOk()
            ->json('data');

        $this->assertNull($data['score']); // нет MCQ — нет оценки
    }
}
