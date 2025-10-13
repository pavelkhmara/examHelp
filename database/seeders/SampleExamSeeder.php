<?php

namespace Database\Seeders;

use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Database\Seeder;

class SampleExamSeeder extends Seeder
{
    public function run(): void
    {
        $exam = Exam::create([
            'title' => 'Demo English B1',
            'description' => 'Short placement demo.',
            'level' => 'B1',
            'is_active' => true,
        ]);

        $q1 = Question::create([
            'exam_id' => $exam->id,
            'type' => 'MCQ',
            'prompt' => 'Choose the correct form: "She ____ to school every day."',
            'position' => 1,
        ]);
        QuestionOption::create(['question_id' => $q1->id, 'text' => 'go',   'is_correct' => false]);
        QuestionOption::create(['question_id' => $q1->id, 'text' => 'goes', 'is_correct' => true]);
        QuestionOption::create(['question_id' => $q1->id, 'text' => 'going','is_correct' => false]);

        $q2 = Question::create([
            'exam_id' => $exam->id,
            'type' => 'TEXT',
            'prompt' => 'Write a short self-introduction (2–3 sentences).',
            'position' => 2,
        ]);
    }
}
