<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamSession;
use App\Services\LanguageApp\RecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SessionController extends Controller
{
    /**
     * POST /api/sessions/start
     *
     * Start a new exam session.
     */
    public function start(Request $req): JsonResponse
    {
        $data = $req->validate([
            'exam_id' => ['required', 'uuid', Rule::exists('exams', 'id')],
            'user_id' => ['nullable', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'mode' => ['nullable', 'string', Rule::in(['practice', 'exam', 'review'])],
            'settings' => ['nullable', 'array'],
            'settings.category_ids' => ['nullable', 'array'],
            'settings.time_limit_sec' => ['nullable', 'integer', 'min:60'],
            'settings.shuffle' => ['nullable', 'boolean'],
            'settings.questions_count' => ['nullable', 'integer', 'min:1'],
        ]);

        $exam = Exam::findOrFail($data['exam_id']);

        $session = ExamSession::create([
            'exam_id' => $exam->id,
            'user_id' => $data['user_id'] ?? null,
            'device_id' => $data['device_id'] ?? null,
            'mode' => $data['mode'] ?? 'practice',
            'settings' => $data['settings'] ?? [],
            'started_at' => now(),
        ]);

        // Load questions based on settings
        $questionsQuery = $exam->questions()->whereNotNull('interaction');

        if (! empty($data['settings']['category_ids'])) {
            $questionsQuery->whereIn('section_id', $data['settings']['category_ids']);
        }

        $questions = $questionsQuery->get();

        if (! empty($data['settings']['shuffle'])) {
            $questions = $questions->shuffle();
        }

        if (! empty($data['settings']['questions_count'])) {
            $questions = $questions->take($data['settings']['questions_count']);
        }

        return response()->json([
            'ok' => true,
            'session_id' => $session->id,
            'mode' => $session->mode,
            'started_at' => $session->started_at->toIso8601String(),
            'questions_count' => $questions->count(),
            'question_ids' => $questions->pluck('id')->values(),
        ], 201);
    }

    /**
     * GET /api/sessions/{session}
     *
     * Get session status.
     */
    public function show(ExamSession $session): JsonResponse
    {
        $score = $session->calculateScore();

        return response()->json([
            'ok' => true,
            'session' => [
                'id' => $session->id,
                'exam_id' => $session->exam_id,
                'mode' => $session->mode,
                'started_at' => $session->started_at->toIso8601String(),
                'finished_at' => $session->finished_at?->toIso8601String(),
                'is_finished' => $session->isFinished(),
                'answers_count' => $session->answers()->count(),
                'score' => $score,
            ],
        ]);
    }

    /**
     * POST /api/sessions/{session}/finish
     *
     * Finish session and calculate final results.
     */
    public function finish(ExamSession $session): JsonResponse
    {
        if ($session->isFinished()) {
            return response()->json([
                'ok' => false,
                'error' => 'Session already finished',
            ], 400);
        }

        $score = $session->calculateScore();

        $session->update([
            'finished_at' => now(),
            'result' => $score,
        ]);

        // Generate recommendations
        $recommendationService = app(RecommendationService::class);
        $recommendations = $recommendationService->generateForSession($session);

        return response()->json([
            'ok' => true,
            'session_id' => $session->id,
            'finished_at' => $session->finished_at->toIso8601String(),
            'result' => $score,
            'recommendations_count' => count($recommendations),
        ]);
    }

    /**
     * GET /api/sessions/{session}/answers
     *
     * Get all answers for session.
     */
    public function answers(ExamSession $session): JsonResponse
    {
        $answers = $session->answers()
            ->with('question:id,type,question_id')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'question_id' => $a->question_id,
                'question_ref' => $a->question?->question_id,
                'answer' => $a->answer,
                'is_correct' => $a->is_correct,
                'points_earned' => $a->points_earned,
                'points_possible' => $a->points_possible,
                'time_spent_sec' => $a->time_spent_sec,
                'attempt' => $a->attempt,
            ]);

        return response()->json([
            'ok' => true,
            'answers' => $answers,
        ]);
    }

    /**
     * GET /api/sessions/{session}/result
     *
     * Get detailed result with breakdown by category.
     */
    public function result(ExamSession $session): JsonResponse
    {
        if (! $session->isFinished()) {
            return response()->json([
                'ok' => false,
                'error' => 'Session not finished yet',
            ], 400);
        }

        // Group answers by category
        $answers = $session->answers()->with('question.section')->get();

        $byCategory = $answers->groupBy(fn ($a) => $a->question?->section_id)->map(function ($group, $categoryId) {
            $section = $group->first()?->question?->section;
            $correct = $group->where('is_correct', true)->count();
            $total = $group->count();

            return [
                'category_id' => $categoryId,
                'category_name' => $section?->name,
                'correct' => $correct,
                'total' => $total,
                'percentage' => $total > 0 ? round(($correct / $total) * 100, 1) : 0,
            ];
        })->values();

        return response()->json([
            'ok' => true,
            'session_id' => $session->id,
            'result' => $session->result,
            'breakdown' => $byCategory,
            'finished_at' => $session->finished_at->toIso8601String(),
        ]);
    }
}
