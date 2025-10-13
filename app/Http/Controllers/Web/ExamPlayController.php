<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use Illuminate\Http\Request;

class ExamPlayController extends Controller
{
    public function index(Request $request)
    {
        $exams = Exam::query()
            ->where('is_active', true)
            ->select(['id','title','level'])
            ->orderBy('title')
            ->get()
            ->toArray();

        return view('exams.index', compact('exams'));
    }

    public function show(Request $request, string $exam)
    {
        $exam = Exam::query()
            ->whereKey($exam)
            ->where('is_active', true)
            ->with(['questions' => function ($q) {
                $q->select(['id','exam_id','type','prompt','position'])->orderBy('position');
            }, 'questions.options' => function ($q) {
                $q->select(['id','question_id','text']); // скрываем is_correct
            }])
            ->firstOrFail();

        // Преобразуем к массиву как в API
        $data = [
            'id' => $exam->id,
            'title' => $exam->title,
            'description' => $exam->description,
            'level' => $exam->level,
            'questions' => $exam->questions->map(function ($q) {
                return [
                    'id' => $q->id,
                    'exam_id' => $q->exam_id,
                    'type' => $q->type,
                    'prompt' => $q->prompt,
                    'position' => $q->position,
                    'options' => $q->options->map(fn($o) => [
                        'id' => $o->id,
                        'question_id' => $o->question_id,
                        'text' => $o->text,
                    ])->values(),
                ];
            })->values(),
        ];

        return view('exams.show', ['exam' => $data]);
    }

    public function play(Request $request, string $attempt)
    {
        return view('attempts.play', ['attemptId' => $attempt]);
    }

    public function result(Request $request, string $attempt)
    {
        return view('attempts.result', ['attemptId' => $attempt]);
    }

}
