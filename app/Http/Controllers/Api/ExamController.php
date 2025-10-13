<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use Illuminate\Http\Request;

class ExamController extends Controller
{
    public function index(Request $request)
    {
        $exams = Exam::query()
            ->where('is_active', true)
            ->select(['id','title','level'])
            ->orderBy('title')
            ->get();

        return response()->json(['data' => $exams]);
    }

    public function show(Exam $exam)
    {
        abort_unless($exam->is_active, 404);

        $exam->load([
            'questions' => fn ($q) => $q->select(['id','exam_id','type','prompt','position'])->orderBy('position'),
            'questions.options' => fn ($q) => $q->select(['id','question_id','text','is_correct']),
        ]);

        $exam->questions->each(function ($question) {
            $question->options->transform(function ($opt) {
                unset($opt->is_correct);
                return $opt;
            });
        });

        return response()->json([
            'data' => [
                'id' => $exam->id,
                'title' => $exam->title,
                'description' => $exam->description,
                'level' => $exam->level,
                'questions' => $exam->questions,
            ],
        ]);
    }
}
