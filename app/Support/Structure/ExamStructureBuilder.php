<?php

namespace App\Support\Structure;

use App\Models\Exam;
use App\Models\GenerationTask;

final class ExamStructureBuilder
{
    public static function build(Exam $exam): array
    {
        $exam->load([
            'categories' => fn ($q) => $q->orderBy('order'),
            'categories.examples' => fn ($q) => $q->orderBy('id'),
        ]);

        $sections = [];
        foreach ($exam->categories as $cat) {
            $examples = [];
            foreach ($cat->examples as $ex) {
                $examples[] = [
                    'id' => (int) $ex->id,
                    'type' => (string) $ex->type, // строго enum whitelist в модели
                    'question' => (string) ($ex->question ?? ''),
                    'payload' => is_array($ex->payload) ? $ex->payload : [],
                    'model_answers' => [
                        'good' => is_array($ex->good_answer) ? $ex->good_answer : [],
                        'average' => is_array($ex->average_answer) ? $ex->average_answer : [],
                        'bad' => is_array($ex->bad_answer) ? $ex->bad_answer : [],
                    ],
                    'rubric_breakdown' => is_array($ex->rubric_breakdown) ? $ex->rubric_breakdown : [],
                ];
            }

            $min = (int) (data_get($cat->meta, 'score_range.min', data_get($cat->meta, 'score_min', 0)));
            $max = (int) (data_get($cat->meta, 'score_range.max', data_get($cat->meta, 'score_max', 0)));

            $sections[] = [
                'id' => (int) $cat->id,
                'key' => (string) ($cat->key ?? ''),
                'title' => (string) ($cat->name ?? ''),
                'description' => (string) ($cat->description ?? ''),
                'order' => (int) ($cat->order ?? 0),
                'score_range' => ['min' => $min, 'max' => $max],
                'scoring_methodology' => (string) (data_get($cat->meta, 'scoring_methodology', '')),
                'examples' => $examples,
            ];
        }

        // total score
        $totalMin = array_sum(array_map(fn ($s) => (int) ($s['score_range']['min'] ?? 0), $sections));
        $totalMax = array_sum(array_map(fn ($s) => (int) ($s['score_range']['max'] ?? 0), $sections));

        // источники: Exam.sources > last completed research-task.result.sources > []
        $task = GenerationTask::query()
            ->where('exam_id', $exam->id)
            ->whereIn('type', ['research', 'research_overview'])
            ->where('status', 'completed')
            ->latest('id')
            ->first();

        $sources = [];
        $rawSources = is_array($exam->sources) && $exam->sources ? $exam->sources
                   : (is_array($task?->result) ? ($task->result['sources'] ?? []) : []);
        foreach ((array) $rawSources as $s) {
            $sources[] = [
                'title' => (string) ($s['title'] ?? ''),
                'url' => (string) ($s['url'] ?? ''),
                'publisher' => (string) ($s['publisher'] ?? ''),
                'from' => (string) ($s['from'] ?? 'web'),
            ];
        }

        $out = [
            'exam' => [
                'id' => (string) $exam->id,
                'slug' => (string) ($exam->slug ?? ''),
                'title' => (string) ($exam->title ?? ''),
                'level' => (string) ($exam->level ?? ''),
                'research_status' => (string) ($exam->research_status ?? ''),
            ],
            'sections' => $sections,
            'meta' => [
                'total_score' => [
                    'min' => (int) data_get($exam->meta, 'exam_structure.total_score.min', $totalMin),
                    'max' => (int) data_get($exam->meta, 'exam_structure.total_score.max', $totalMax),
                ],
                'generated_at' => ($task?->updated_at?->toISOString()) ?? now()->toISOString(),
                'task_id' => (int) ($task?->id ?? 0),
            ],
            'sources' => $sources,
        ];

        // гарантированно без null (преобразуем null → '')
        return self::sanitizeNoNulls($out);
    }

    private static function sanitizeNoNulls(array $v): array
    {
        array_walk_recursive($v, function (&$item) {
            if ($item === null) {
                $item = '';
            }
        });

        return $v;
    }
}
