<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use Illuminate\Http\Request;

class ExamPlayController extends Controller
{
    public function index(Request $request): \Illuminate\View\View
    {
        $exams = Exam::query()
            ->where('is_active', true)
            ->select(['id', 'title', 'level'])
            ->orderBy('title')
            ->get()
            ->toArray();

        return view('exams.index', compact('exams'));
    }

    public function show(Request $request, string $exam): \Illuminate\View\View
    {
        // NOTE: This is legacy code that needs refactoring
        // Questions no longer use 'options' relationship - they store options in JSON interaction field
        // @phpstan-ignore-next-line
        $exam = Exam::query()
            ->whereKey($exam)
            ->where('is_active', true)
            ->with(['questions' => function ($q) {
                $q->select(['id', 'exam_id', 'type', 'prompt', 'position'])->orderBy('position');
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
                    // Options are now stored in interaction JSON field, not as separate records
                    'options' => [],
                ];
            })->values(),
        ];

        return view('exams.show', ['exam' => $data]);
    }

    public function play(Request $request, string $attempt): \Illuminate\View\View
    {
        return view('attempts.play', ['attemptId' => $attempt]);
    }

    public function result(Request $request, string $attempt): \Illuminate\View\View
    {
        return view('attempts.result', ['attemptId' => $attempt]);
    }
}
