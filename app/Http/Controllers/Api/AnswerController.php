<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Answer;
use App\Models\ExamSession;
use App\Models\Question;
use App\Services\LanguageApp\AnswerEvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnswerController extends Controller
{
    public function __construct(
        private AnswerEvaluationService $evaluationService
    ) {}

    /**
     * POST /api/answers/submit
     *
     * Submit an answer for a question.
     */
    public function submit(Request $req): JsonResponse
    {
        $data = $req->validate([
            'session_id' => ['required', 'uuid', Rule::exists('exam_sessions', 'id')],
            'question_id' => ['required', 'integer', Rule::exists('questions', 'id')],
            'answer' => ['required'], // Can be string, array, or object
            'time_spent_sec' => ['nullable', 'integer', 'min:0'],
            'attempt' => ['nullable', 'integer', 'min:1'],
        ]);

        $session = ExamSession::findOrFail($data['session_id']);

        if ($session->isFinished()) {
            return response()->json([
                'ok' => false,
                'error' => 'Session already finished',
            ], 400);
        }

        $question = Question::findOrFail($data['question_id']);

        // Check if answer already exists for this attempt
        $attempt = $data['attempt'] ?? 1;
        $existingAnswer = Answer::where('session_id', $session->id)
            ->where('question_id', $question->id)
            ->where('attempt', $attempt)
            ->first();

        if ($existingAnswer && ! $session->isPracticeMode()) {
            return response()->json([
                'ok' => false,
                'error' => 'Answer already submitted for this question',
            ], 400);
        }

        // Evaluate the answer
        $evaluation = $this->evaluationService->evaluate($question, $data['answer']);

        // Create or update answer
        $answer = Answer::updateOrCreate(
            [
                'session_id' => $session->id,
                'question_id' => $question->id,
                'attempt' => $attempt,
            ],
            [
                'answer' => $data['answer'],
                'evaluation' => $evaluation,
                'is_correct' => $evaluation['is_correct'] ?? null,
                'points_earned' => $evaluation['points_earned'] ?? null,
                'points_possible' => $evaluation['points_possible'] ?? null,
                'time_spent_sec' => $data['time_spent_sec'] ?? null,
            ]
        );

        // Build response based on mode
        $response = [
            'ok' => true,
            'answer_id' => $answer->id,
            'saved' => true,
        ];

        // Include evaluation for practice/review mode
        if ($session->isPracticeMode() || $session->mode === 'review') {
            $response['evaluation'] = [
                'is_correct' => $evaluation['is_correct'],
                'points_earned' => $evaluation['points_earned'],
                'points_possible' => $evaluation['points_possible'],
                'feedback' => $evaluation['feedback'] ?? null,
                'correct_answer' => $evaluation['correct_answer'] ?? null,
            ];
        }

        return response()->json($response);
    }

    /**
     * GET /api/answers/{answer}
     *
     * Get a single answer with evaluation.
     */
    public function show(Answer $answer): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'answer' => [
                'id' => $answer->id,
                'session_id' => $answer->session_id,
                'question_id' => $answer->question_id,
                'answer' => $answer->answer,
                'evaluation' => $answer->evaluation,
                'is_correct' => $answer->is_correct,
                'points_earned' => $answer->points_earned,
                'points_possible' => $answer->points_possible,
                'time_spent_sec' => $answer->time_spent_sec,
                'attempt' => $answer->attempt,
                'created_at' => $answer->created_at->toIso8601String(),
            ],
        ]);
    }
}
