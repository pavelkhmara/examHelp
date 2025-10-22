<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\ExamExampleQuestion;

class EvaluateTextDemoSeeder extends Seeder
{
    public function run(): void
    {
        $exam = Exam::factory()->create([
            'title'     => 'English B1 Demo',
            'level'     => 'B1',
            'is_active' => true,
        ]);

        $cat = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'key'     => 'writing',
            'name'    => 'Writing',
            'order'   => 1,
        ]);

        $ex = ExamExampleQuestion::factory()->create([
            'exam_id'          => $exam->id,
            'exam_category_id' => $cat->id,
            // question/payload/тип придут из фабрики
        ]);

        $this->command->info("exam_id={$exam->id}");
        $this->command->info("category_id={$cat->id}");
        $this->command->info("question_id={$ex->id}");
    }
}
