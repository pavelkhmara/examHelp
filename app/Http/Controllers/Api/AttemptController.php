<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\AttemptAnswer;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AttemptController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'exam_id' => ['required','uuid', Rule::exists('exams','id')->where('is_active', true)],
        ]);

        $attempt = Attempt::create([
            'exam_id' => $data['exam_id'],
            'started_at' => now(),
        ]);

        return response()->json(['data' => ['id' => $attempt->id]], 201);
    }

    public function answer(Request $request, Attempt $attempt)
    {
        abort_if($attempt->completed_at !== null, 422, 'Attempt already completed.');

        $data = $request->validate([
            'question_id' => ['required','uuid', Rule::exists('questions','id')->where('exam_id', $attempt->exam_id)],
            'type' => ['required', Rule::in(['MCQ','TEXT'])],
            'selected_option_id' => ['nullable','uuid'],
            'text_answer' => ['nullable','string'],
        ]);

        if ($data['type'] === 'MCQ') {
            $request->validate([
                'selected_option_id' => [
                    'required','uuid',
                    Rule::exists('question_options','id')->where('question_id', $data['question_id'])
                ],
            ]);
        }

        // upsert by (attempt_id, question_id)
        return DB::transaction(function () use ($attempt, $data) {
            $answerAttrs = [
                'attempt_id' => $attempt->id,
                'question_id' => $data['question_id'],
            ];

            $payload = [
                'selected_option_id' => $data['type'] === 'MCQ' ? $data['selected_option_id'] : null,
                'text_answer' => $data['type'] === 'TEXT' ? ($data['text_answer'] ?? null) : null,
            ];

            // Определяем корректность для MCQ сразу
            if ($data['type'] === 'MCQ' && $payload['selected_option_id']) {
                $isCorrect = QuestionOption::where('id', $payload['selected_option_id'])->value('is_correct');
                $payload['is_correct'] = (bool) $isCorrect;
            } else {
                $payload['is_correct'] = null;
            }

            AttemptAnswer::updateOrCreate($answerAttrs, $payload);

            return response()->json(['data' => ['saved' => true]]);
        });
    }

    public function complete(Request $request, Attempt $attempt)
    {
        abort_if($attempt->completed_at !== null, 422, 'Attempt already completed.');

        $mcqQuestionIds = Question::where('exam_id', $attempt->exam_id)
            ->where('type', 'MCQ')
            ->pluck('id');

        $total = $mcqQuestionIds->count();

        $correct = 0;
        if ($total > 0) {
            $correct = AttemptAnswer::where('attempt_id', $attempt->id)
                ->whereIn('question_id', $mcqQuestionIds)
                ->where('is_correct', true)
                ->count();
        }

        $score = $total > 0 ? (int) round(100 * $correct / $total) : null;

        $attempt->update([
            'completed_at' => now(),
            'score' => $score,
        ]);

        return response()->json(['data' => ['score' => $score]]);
    }
}
