<?php

namespace App\Services\LanguageApp;

use App\Models\ExamSession;
use App\Models\Recommendation;

/**
 * Generates personalized recommendations based on exam session results.
 *
 * Analyzes:
 * - Weak categories (low accuracy)
 * - Strong categories (high accuracy)
 * - Question type performance
 * - Time management patterns
 *
 * Future: LLM-powered recommendations, resource suggestions
 */
class RecommendationService
{
    private const WEAKNESS_THRESHOLD = 0.6; // Below 60% is weak

    private const STRENGTH_THRESHOLD = 0.85; // Above 85% is strong

    /**
     * Generate recommendations for a completed session.
     *
     * @return Recommendation[]
     */
    public function generateForSession(ExamSession $session): array
    {
        if (! $session->isFinished()) {
            return [];
        }

        $recommendations = [];

        // Analyze by category
        $categoryAnalysis = $this->analyzeByCategory($session);
        $recommendations = array_merge($recommendations, $this->generateCategoryRecommendations($session, $categoryAnalysis));

        // Analyze by question type
        $typeAnalysis = $this->analyzeByQuestionType($session);
        $recommendations = array_merge($recommendations, $this->generateTypeRecommendations($session, $typeAnalysis));

        // Analyze time management
        $timeAnalysis = $this->analyzeTimeManagement($session);
        $recommendations = array_merge($recommendations, $this->generateTimeRecommendations($session, $timeAnalysis));

        // Persist recommendations
        foreach ($recommendations as $rec) {
            Recommendation::create([
                'session_id' => $session->id,
                'type' => $rec['type'],
                'category' => $rec['category'] ?? null,
                'category_id' => $rec['category_id'] ?? null,
                'priority' => $rec['priority'],
                'title' => $rec['title'],
                'description' => $rec['description'] ?? null,
                'data' => $rec['data'] ?? null,
            ]);
        }

        return $recommendations;
    }

    /**
     * Get recommendations for a session.
     */
    public function getForSession(ExamSession $session): array
    {
        return $session->recommendations()
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->get()
            ->toArray();
    }

    /**
     * Analyze performance by category.
     */
    private function analyzeByCategory(ExamSession $session): array
    {
        $answers = $session->answers()->with('question.section')->get();

        $byCategory = [];
        foreach ($answers as $answer) {
            $categoryId = $answer->question?->section_id;
            $categoryName = $answer->question?->section?->name ?? 'Unknown';

            if (! isset($byCategory[$categoryId])) {
                $byCategory[$categoryId] = [
                    'id' => $categoryId,
                    'name' => $categoryName,
                    'correct' => 0,
                    'total' => 0,
                    'questions' => [],
                ];
            }

            $byCategory[$categoryId]['total']++;
            if ($answer->is_correct) {
                $byCategory[$categoryId]['correct']++;
            } else {
                $byCategory[$categoryId]['questions'][] = $answer->question_id;
            }
        }

        // Calculate accuracy
        foreach ($byCategory as &$cat) {
            $cat['accuracy'] = $cat['total'] > 0 ? $cat['correct'] / $cat['total'] : 0;
        }

        return $byCategory;
    }

    /**
     * Analyze performance by question type.
     */
    private function analyzeByQuestionType(ExamSession $session): array
    {
        $answers = $session->answers()->with('question')->get();

        $byType = [];
        foreach ($answers as $answer) {
            $type = $answer->question?->type ?? 'unknown';

            if (! isset($byType[$type])) {
                $byType[$type] = [
                    'type' => $type,
                    'correct' => 0,
                    'total' => 0,
                ];
            }

            $byType[$type]['total']++;
            if ($answer->is_correct) {
                $byType[$type]['correct']++;
            }
        }

        foreach ($byType as &$t) {
            $t['accuracy'] = $t['total'] > 0 ? $t['correct'] / $t['total'] : 0;
        }

        return $byType;
    }

    /**
     * Analyze time management.
     */
    private function analyzeTimeManagement(ExamSession $session): array
    {
        $answers = $session->answers()->whereNotNull('time_spent_sec')->with('question')->get();

        if ($answers->isEmpty()) {
            return ['has_data' => false];
        }

        $times = $answers->pluck('time_spent_sec')->toArray();
        $avgTime = array_sum($times) / count($times);

        // Find questions that took too long
        $slowQuestions = $answers->filter(fn ($a) => $a->time_spent_sec > $avgTime * 2)->pluck('question_id')->toArray();

        // Correlation: slow = incorrect?
        $slowAndWrong = $answers->filter(fn ($a) => $a->time_spent_sec > $avgTime * 1.5 && ! $a->is_correct)->count();

        return [
            'has_data' => true,
            'avg_time_sec' => round($avgTime, 1),
            'total_time_sec' => array_sum($times),
            'slow_questions' => $slowQuestions,
            'slow_and_wrong_count' => $slowAndWrong,
        ];
    }

    /**
     * Generate recommendations based on category analysis.
     */
    private function generateCategoryRecommendations(ExamSession $session, array $analysis): array
    {
        $recommendations = [];

        foreach ($analysis as $cat) {
            if ($cat['accuracy'] < self::WEAKNESS_THRESHOLD && $cat['total'] >= 3) {
                $recommendations[] = [
                    'type' => Recommendation::TYPE_WEAKNESS,
                    'category' => $cat['name'],
                    'category_id' => $cat['id'],
                    'priority' => $cat['accuracy'] < 0.4 ? Recommendation::PRIORITY_HIGH : Recommendation::PRIORITY_MEDIUM,
                    'title' => "Weak area: {$cat['name']}",
                    'description' => sprintf(
                        'You scored %.0f%% (%d/%d) in this category. Review the material and practice more questions.',
                        $cat['accuracy'] * 100,
                        $cat['correct'],
                        $cat['total']
                    ),
                    'data' => [
                        'accuracy' => $cat['accuracy'],
                        'correct' => $cat['correct'],
                        'total' => $cat['total'],
                        'missed_questions' => $cat['questions'],
                    ],
                ];
            } elseif ($cat['accuracy'] >= self::STRENGTH_THRESHOLD && $cat['total'] >= 3) {
                $recommendations[] = [
                    'type' => Recommendation::TYPE_STRENGTH,
                    'category' => $cat['name'],
                    'category_id' => $cat['id'],
                    'priority' => Recommendation::PRIORITY_LOW,
                    'title' => "Strong area: {$cat['name']}",
                    'description' => sprintf(
                        'Excellent! You scored %.0f%% (%d/%d) in this category.',
                        $cat['accuracy'] * 100,
                        $cat['correct'],
                        $cat['total']
                    ),
                    'data' => [
                        'accuracy' => $cat['accuracy'],
                        'correct' => $cat['correct'],
                        'total' => $cat['total'],
                    ],
                ];
            }
        }

        return $recommendations;
    }

    /**
     * Generate recommendations based on question type analysis.
     */
    private function generateTypeRecommendations(ExamSession $session, array $analysis): array
    {
        $recommendations = [];
        $typeNames = [
            'single_select' => 'Multiple Choice',
            'multi_select' => 'Multiple Answer',
            'true_false' => 'True/False',
            'fill_blank' => 'Fill in the Blank',
            'matching' => 'Matching',
            'ordering' => 'Ordering',
        ];

        foreach ($analysis as $t) {
            if ($t['accuracy'] < self::WEAKNESS_THRESHOLD && $t['total'] >= 3) {
                $typeName = $typeNames[$t['type']] ?? $t['type'];
                $recommendations[] = [
                    'type' => Recommendation::TYPE_SUGGESTION,
                    'category' => null,
                    'priority' => Recommendation::PRIORITY_MEDIUM,
                    'title' => "Practice {$typeName} questions",
                    'description' => sprintf(
                        'You scored %.0f%% on %s questions. Try more practice with this format.',
                        $t['accuracy'] * 100,
                        $typeName
                    ),
                    'data' => [
                        'question_type' => $t['type'],
                        'accuracy' => $t['accuracy'],
                    ],
                ];
            }
        }

        return $recommendations;
    }

    /**
     * Generate recommendations based on time analysis.
     */
    private function generateTimeRecommendations(ExamSession $session, array $analysis): array
    {
        if (! $analysis['has_data']) {
            return [];
        }

        $recommendations = [];

        if ($analysis['slow_and_wrong_count'] >= 3) {
            $recommendations[] = [
                'type' => Recommendation::TYPE_SUGGESTION,
                'category' => null,
                'priority' => Recommendation::PRIORITY_MEDIUM,
                'title' => 'Time management tip',
                'description' => sprintf(
                    'You spent extra time on %d questions that were still incorrect. Consider moving on and returning to difficult questions later.',
                    $analysis['slow_and_wrong_count']
                ),
                'data' => $analysis,
            ];
        }

        return $recommendations;
    }
}
