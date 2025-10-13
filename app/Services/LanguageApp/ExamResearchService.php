<?php

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\ExamExampleQuestion;
use App\Models\GenerationTask;

class ExamResearchService extends AbstractAiService
{
    public function runPipeline(Exam $exam, GenerationTask $task): void
    {
        // 1) Overview
        $req1 = ['exam_slug' => $exam->slug, 'stage' => 'overview'];
        $res1 = $this->callAi($req1);
        $this->log($task, 'overview', $req1, $res1);
        $exam->update(['research_status' => 'running_overview']);

        // 2) Categories (пример)
        $categories = [
            ['key' => 'listening', 'name' => 'Listening'],
            ['key' => 'speaking',  'name' => 'Speaking'],
            ['key' => 'reading',   'name' => 'Reading'],
            ['key' => 'writing',   'name' => 'Writing'],
        ];
        foreach ($categories as $c) {
            ExamCategory::firstOrCreate(
                ['exam_id' => $exam->id, 'key' => $c['key']],
                ['name' => $c['name'], 'meta' => ['source' => 'llm_stub']]
            );
        }
        $exam->update(['research_status' => 'running_categories', 'categories_count' => count($categories)]);

        // 3) Example questions (по 1 на категорию — stub)
        foreach ($exam->categories as $cat) {
            $reqQ = ['exam_slug' => $exam->slug, 'stage' => 'examples', 'category' => $cat->key];
            $resQ = $this->callAi($reqQ);
            $this->log($task, 'examples', $reqQ, $resQ);

            ExamExampleQuestion::create([
                'exam_id' => $exam->id,
                'exam_category_id' => $cat->id,
                'question' => "Example question for {$cat->name}",
                'good_answer' => ['text' => 'Good answer (stub)'],
                'average_answer' => ['text' => 'Average answer (stub)'],
                'bad_answer' => ['text' => 'Bad answer (stub)'],
                'rubric_breakdown' => ['fluency' => 5, 'accuracy' => 5, 'content' => 5],
            ]);
        }
        $exam->update(['research_status' => 'running_examples', 'examples_count' => $exam->examples()->count()]);

        // 4) Rubrics (stub)
        $reqR = ['exam_slug' => $exam->slug, 'stage' => 'rubrics'];
        $resR = $this->callAi($reqR);
        $this->log($task, 'rubrics', $reqR, $resR);
        $exam->update(['research_status' => 'running_rubrics']);
    }
}
