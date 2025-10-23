<?php

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\ExamExampleQuestion;
use App\Models\GenerationLog;

/**
 * Heuristic Evaluation: быстрый скоринг без LLM.
 * (LLM-ветка включается фичефлагом в config('ai.evaluation.enable_llm'))
 */
class EvaluationService
{
    public function evaluateText(
        Exam $exam,
        ?ExamCategory $category,
        ?ExamExampleQuestion $example,
        string $answer
    ): array {
        $answer = trim($answer);

        // Если есть пример с эталонами — считаем похожесть на good/avg/bad
        $score = 0;
        $which = 'bad';
        $rubric = [
            'content' => 0,
            'clarity' => 0,
            'language' => 0,
        ];
        $feedback = 'Please provide a more detailed and structured answer.';

        if ($example && ($example->good_answer || $example->average_answer || $example->bad_answer)) {
            $simGood = $this->similarity($answer, (string) $example->good_answer);
            $simAvg = $this->similarity($answer, (string) $example->average_answer);
            $simBad = $this->similarity($answer, (string) $example->bad_answer);

            // Небольшая «сглаженная» формула
            $score = max(
                (int) round($simGood * 1.00),
                (int) round($simAvg * 0.66),
                (int) round($simBad * 0.33),
            );

            if ($score > 100) {
                $score = 100;
            }
            if ($score < 0) {
                $score = 0;
            }

            $maxSim = max($simGood, $simAvg, $simBad);
            $which = $maxSim === $simGood ? 'good' : ($maxSim === $simAvg ? 'average' : 'bad');

            // Простейший разбор рубрики (суррогаты)
            $rubric['content'] = (int) round($this->overlap($answer, (string) $example->good_answer) * 100);
            $rubric['clarity'] = (int) round($this->clarity($answer) * 100);
            $rubric['language'] = (int) round($this->language($answer) * 100);

            $feedback = match ($which) {
                'good' => 'Great answer: it matches the expected content and structure.',
                'average' => 'Decent answer: try to add key points and make the structure clearer.',
                default => 'Weak answer: add the main ideas and use clearer language.',
            };
        } else {
            // Нет эталонов — даём безопасную «общую» оценку
            $len = mb_strlen($answer);
            $score = $len >= 200 ? 70 : ($len >= 80 ? 50 : 30);
            $rubric['content'] = $len >= 100 ? 60 : 30;
            $rubric['clarity'] = $this->clarity($answer) * 100;
            $rubric['language'] = $this->language($answer) * 100;
        }

        // Снимок в GenerationLog (нулевые токены, мы без LLM)
        GenerationLog::create([
            'generation_task_id' => null,
            'stage' => 'evaluate_text:heuristic',
            'request' => [
                'exam_id' => $exam->id,
                'category_id' => $category?->id,
                'example_id' => $example?->id,
            ],
            'response' => [
                'score' => $score,
                'feedback' => $feedback,
                'rubric_breakdown' => $rubric,
                'match' => $which,
            ],
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ]);

        return [
            'score' => $score,
            'feedback' => $feedback,
            'rubric_breakdown' => $rubric,
            'match' => $which,
        ];
    }

    /** 0..100 похожесть (на базе similar_text) */
    private function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        similar_text(mb_strtolower($a), mb_strtolower($b), $pct);

        return (float) $pct; // уже в процентах
    }

    /** простая доля общих «значимых» слов с эталоном (0..1) */
    private function overlap(string $user, string $gold): float
    {
        $tw = fn (string $s) => array_values(array_filter(preg_split('/\W+/u', mb_strtolower($s)), fn ($w) => mb_strlen($w) > 2));
        $ua = array_unique($tw($user));
        $ga = array_unique($tw($gold));
        if (! $ua || ! $ga) {
            return 0.0;
        }
        $intersect = count(array_intersect($ua, $ga));

        return $intersect / max(count($ga), 1);
    }

    /** суррогат «ясности» — средняя длина предложения и пунктуация (0..1) */
    private function clarity(string $s): float
    {
        $sent = preg_split('/[.!?]+/u', trim($s)) ?: [];
        $sent = array_values(array_filter($sent, fn ($x) => mb_strlen(trim($x)) > 0));
        if (! $sent) {
            return 0.3;
        }
        $avgLen = array_sum(array_map('mb_strlen', $sent)) / max(count($sent), 1);
        $hasPunct = (bool) preg_match('/[,:;()]/u', $s);
        $score = 0.5 + ($hasPunct ? 0.2 : 0.0) + (min($avgLen, 200) / 400.0);

        return max(0.0, min(1.0, $score));
    }

    /** суррогат «языка» — разнообразие лексики (0..1) */
    private function language(string $s): float
    {
        $words = array_values(array_filter(preg_split('/\W+/u', mb_strtolower($s)), fn ($w) => mb_strlen($w) > 2));
        $uniq = count(array_unique($words));
        $total = count($words);
        if ($total === 0) {
            return 0.2;
        }

        return max(0.0, min(1.0, $uniq / max($total, 1)));
    }
}
