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
            ->select(['id', 'title', 'level'])
            ->orderBy('title')
            ->get();

        return response()->json(['data' => $exams]);
    }

    public function show(Exam $exam)
    {
        abort_unless($exam->is_active, 404);

        $exam->load([
            'questions' => fn ($q) => $q->select(['id', 'exam_id', 'type', 'prompt', 'position'])->orderBy('position'),
            'questions.options' => fn ($q) => $q->select(['id', 'question_id', 'text', 'is_correct']),
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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'level' => ['required', 'string', 'in:A1,A2,B1,B2,C1,C2'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:exams,slug'],
            'is_active' => ['nullable', 'boolean'],
            'sources' => ['nullable', 'array'],
            'meta' => ['nullable', 'array'],
        ]);

        // Автогенерация slug если не указан
        if (empty($validated['slug'])) {
            $validated['slug'] = \Illuminate\Support\Str::slug($validated['title']);
        }

        // Дефолтные значения
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['research_status'] = 'queued';
        $validated['categories_count'] = 0;
        $validated['examples_count'] = 0;

        $exam = Exam::create($validated);

        return response()->json([
            'data' => [
                'id' => $exam->id,
                'slug' => $exam->slug,
                'title' => $exam->title,
                'description' => $exam->description,
                'level' => $exam->level,
                'is_active' => $exam->is_active,
                'research_status' => $exam->research_status,
                'created_at' => $exam->created_at,
            ],
        ], 201);
    }
}
