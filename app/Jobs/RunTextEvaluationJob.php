<?php

namespace App\Jobs;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\ExamExampleQuestion;
use App\Models\GenerationTask;
use App\Services\LanguageApp\EvaluationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class RunTextEvaluationJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public string $taskId) {}

    public function handle(): void
    {
        $task = GenerationTask::findOrFail($this->taskId);
        $task->update(['status' => 'running']);

        $req = (array) ($task->request ?? []);
        $exam = Exam::findOrFail($req['exam_id']);
        $category = isset($req['category_id']) ? ExamCategory::find($req['category_id']) : null;
        $example = isset($req['question_id']) ? ExamExampleQuestion::find($req['question_id']) : null;
        $answer = (string) ($req['answer_text'] ?? '');

        $svc = app(EvaluationService::class);
        $res = $svc->evaluateText($exam, $category, $example, $answer);

        $task->update([
            'status' => 'completed',
            'result' => $res,
        ]);
    }
}
