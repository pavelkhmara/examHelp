<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamSession;
use App\Models\Recommendation;
use App\Services\LanguageApp\RecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecommendationController extends Controller
{
    public function __construct(
        private RecommendationService $service
    ) {}

    /**
     * GET /api/sessions/{session}/recommendations
     *
     * Get recommendations for a session.
     */
    public function forSession(ExamSession $session): JsonResponse
    {
        if (! $session->isFinished()) {
            return response()->json([
                'ok' => false,
                'error' => 'Session not finished yet. Recommendations are generated after finishing.',
            ], 400);
        }

        $recommendations = $session->recommendations()
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->orderBy('type')
            ->get()
            ->map(fn (Recommendation $r) => [
                'id' => $r->id,
                'type' => $r->type,
                'category' => $r->category,
                'category_id' => $r->category_id,
                'priority' => $r->priority,
                'title' => $r->title,
                'description' => $r->description,
                'data' => $r->data,
            ]);

        // Group by type for easier consumption
        $grouped = [
            'weaknesses' => $recommendations->where('type', Recommendation::TYPE_WEAKNESS)->values(),
            'strengths' => $recommendations->where('type', Recommendation::TYPE_STRENGTH)->values(),
            'suggestions' => $recommendations->where('type', Recommendation::TYPE_SUGGESTION)->values(),
            'resources' => $recommendations->where('type', Recommendation::TYPE_RESOURCE)->values(),
        ];

        return response()->json([
            'ok' => true,
            'session_id' => $session->id,
            'total' => $recommendations->count(),
            'recommendations' => $recommendations,
            'grouped' => $grouped,
        ]);
    }

    /**
     * POST /api/sessions/{session}/recommendations/regenerate
     *
     * Regenerate recommendations for a session.
     */
    public function regenerate(ExamSession $session): JsonResponse
    {
        if (! $session->isFinished()) {
            return response()->json([
                'ok' => false,
                'error' => 'Session not finished yet.',
            ], 400);
        }

        // Clear existing recommendations
        $session->recommendations()->delete();

        // Generate new ones
        $recommendations = $this->service->generateForSession($session);

        return response()->json([
            'ok' => true,
            'session_id' => $session->id,
            'recommendations_count' => count($recommendations),
        ]);
    }

    /**
     * GET /api/users/{userId}/recommendations
     *
     * Get aggregated recommendations across all sessions for a user.
     */
    public function forUser(Request $req, string $userId): JsonResponse
    {
        $sessions = ExamSession::where('user_id', $userId)
            ->whereNotNull('finished_at')
            ->with('recommendations')
            ->latest('finished_at')
            ->take(10)
            ->get();

        if ($sessions->isEmpty()) {
            return response()->json([
                'ok' => true,
                'user_id' => $userId,
                'message' => 'No completed sessions found.',
                'recommendations' => [],
            ]);
        }

        // Aggregate weaknesses across sessions
        $weaknessCounts = [];
        foreach ($sessions as $session) {
            foreach ($session->recommendations as $rec) {
                if ($rec->type === Recommendation::TYPE_WEAKNESS && $rec->category) {
                    $key = $rec->category;
                    if (! isset($weaknessCounts[$key])) {
                        $weaknessCounts[$key] = [
                            'category' => $rec->category,
                            'category_id' => $rec->category_id,
                            'count' => 0,
                            'avg_accuracy' => 0,
                            'accuracies' => [],
                        ];
                    }
                    $weaknessCounts[$key]['count']++;
                    if (isset($rec->data['accuracy'])) {
                        $weaknessCounts[$key]['accuracies'][] = $rec->data['accuracy'];
                    }
                }
            }
        }

        // Calculate average accuracy and sort by frequency
        $persistentWeaknesses = [];
        foreach ($weaknessCounts as &$w) {
            if ($w['count'] >= 2) { // Appears in 2+ sessions
                $w['avg_accuracy'] = ! empty($w['accuracies'])
                    ? array_sum($w['accuracies']) / count($w['accuracies'])
                    : 0;
                unset($w['accuracies']);
                $persistentWeaknesses[] = $w;
            }
        }

        usort($persistentWeaknesses, fn ($a, $b) => $b['count'] <=> $a['count']);

        return response()->json([
            'ok' => true,
            'user_id' => $userId,
            'sessions_analyzed' => $sessions->count(),
            'persistent_weaknesses' => $persistentWeaknesses,
            'latest_session' => [
                'id' => $sessions->first()->id,
                'finished_at' => $sessions->first()->finished_at->toIso8601String(),
                'result' => $sessions->first()->result,
            ],
        ]);
    }
}
