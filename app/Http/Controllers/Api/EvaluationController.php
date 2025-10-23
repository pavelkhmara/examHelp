<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunTextEvaluationJob;
use App\Models\Evaluation;
use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\ExamExampleQuestion;
use App\Models\GenerationTask;
use App\Services\LanguageApp\EvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EvaluationController extends Controller
{
    /**
     * POST /api/evaluate/text
     * Body: { exam_id, category_id?, question_id?, answer_text, async?=false }
     */
    public function evaluateText(Request $req): JsonResponse
    {
        $data = $req->validate([
            'exam_id' => ['required', 'uuid', Rule::exists('exams', 'id')],
            'category_id' => ['nullable', 'integer', Rule::exists('exam_categories', 'id')],
            'question_id' => ['nullable', 'integer', Rule::exists('exam_example_questions', 'id')],
            'answer_text' => ['required', 'string', 'min:1'],
            'async' => ['sometimes', 'boolean'],
        ]);

        $async = (bool) ($data['async'] ?? false);

        /** @var Exam $exam */
        $exam = Exam::findOrFail($data['exam_id']);

        // async вариант — через очередь и GenerationTask
        if ($async) {
            $task = GenerationTask::create([
                'exam_id' => $exam->id,
                'type' => 'evaluate_text',
                'status' => 'queued',
                'request' => $data,
            ]);

            RunTextEvaluationJob::dispatch($task->id);

            return response()->json([
                'ok' => true,
                'queued' => true,
                'task_id' => $task->id,
            ], 202);
        }

        // sync вариант — сразу считаем
        $svc = app(EvaluationService::class);
        $res = $svc->evaluateText(
            $exam,
            $data['category_id'] ? ExamCategory::find($data['category_id']) : null,
            $data['question_id'] ? ExamExampleQuestion::find($data['question_id']) : null,
            $data['answer_text']
        );

        // Сохраним один снапшот в Evaluation (по желанию)
        Evaluation::create([
            'user_id' => null,
            'exam_id' => $exam->id,
            'exam_category_id' => $data['category_id'] ?? null,
            'answer' => $data['answer_text'],
            'result' => $res,
        ]);

        return response()->json(['ok' => true] + $res, 200);
    }
}
