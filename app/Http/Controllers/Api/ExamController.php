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

    /**
     * Update exam fields from MissingFieldsForm
     * Used by identity-clarifier card to fill missing fields during research
     */
    public function updateFields(Request $request, Exam $exam)
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'level' => ['nullable', 'string', 'in:A1,A2,B1,B2,C1,C2'],
            'description' => ['nullable', 'string'],
            'user_input_updates' => ['nullable', 'array'],
        ]);

        // Update direct exam fields
        $updated = [];
        foreach (['title', 'level', 'description'] as $field) {
            if (isset($validated[$field])) {
                $exam->{$field} = $validated[$field];
                $updated[] = $field;
            }
        }

        // Update user_input fields
        if (!empty($validated['user_input_updates'])) {
            $userInput = is_array($exam->user_input) ? $exam->user_input : [];
            $userInput = array_merge($userInput, $validated['user_input_updates']);
            $exam->user_input = $userInput;
            $updated[] = 'user_input';
        }

        $exam->save();

        \Illuminate\Support\Facades\Log::info('[ExamController] Fields updated via MissingFieldsForm', [
            'exam_id' => $exam->id,
            'updated_fields' => $updated,
        ]);

        return response()->json([
            'success' => true,
            'updated_fields' => $updated,
        ]);
    }

    /**
     * Dismiss a card for the exam
     */
    public function dismissCard(Request $request, Exam $exam)
    {
        $validated = $request->validate([
            'card_type' => ['required', 'string', 'in:missing_fields,fields_changed,stalled_task'],
        ]);

        /** @var \App\Services\Nova\CardManager $cardManager */
        $cardManager = app(\App\Services\Nova\CardManager::class);
        $cardManager->dismissCard($exam, $validated['card_type']);

        \Illuminate\Support\Facades\Log::info('[ExamController] Card dismissed', [
            'exam_id' => $exam->id,
            'card_type' => $validated['card_type'],
        ]);

        return response()->json([
            'success' => true,
        ]);
    }
}
