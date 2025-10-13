<?php

namespace Tests\Feature\Api;

use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExamsReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_active_exams(): void
    {
        $active = Exam::factory()->create(['is_active' => true, 'title' => 'A']);
        $inactive = Exam::factory()->create(['is_active' => false, 'title' => 'B']);

        $res = $this->getJson('/api/exams')
            ->assertOk()
            ->json('data');

        $ids = collect($res)->pluck('id');
        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($inactive->id));
    }

    public function test_show_exam_with_questions_and_options_without_answers(): void
    {
        $exam = Exam::factory()->create(['is_active' => true]);

        $q1 = Question::factory()->create([
            'id' => (string) Str::uuid(),
            'exam_id' => $exam->id,
            'type' => 'MCQ',
            'prompt' => 'Pick one',
            'position' => 1,
        ]);

        QuestionOption::factory()->create(['question_id' => $q1->id, 'text' => 'A', 'is_correct' => true]);
        QuestionOption::factory()->create(['question_id' => $q1->id, 'text' => 'B', 'is_correct' => false]);

        $data = $this->getJson("/api/exams/{$exam->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame($exam->id, $data['id']);
        $this->assertNotEmpty($data['questions']);
        $opt = collect($data['questions'][0]['options'])[0];
        $this->assertArrayNotHasKey('is_correct', $opt);
    }

    public function test_inactive_exam_returns_404(): void
    {
        $exam = Exam::factory()->create(['is_active' => false]);

        $this->getJson("/api/exams/{$exam->id}")
            ->assertNotFound();
    }
}
