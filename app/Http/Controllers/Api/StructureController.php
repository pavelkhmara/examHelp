<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\GenerationTask;
use Illuminate\Http\JsonResponse;

class StructureController extends Controller
{
    public function show(Exam $exam): JsonResponse
    {
        $task = GenerationTask::query()
            ->where('exam_id', $exam->id)
            ->whereIn('type', ['research', 'research_overview'])
            ->where('status', 'completed')
            ->latest('id')
            ->first();

        if (!$task || !is_array($task->result)) {
            return response()->json([
                'message' => 'Structure not ready for this exam.',
                'code'    => 'structure_not_ready',
            ], 404);
        }

        $result = $task->result;

        // sources
        $sources = [];
        if (!empty($result['sources']) && is_array($result['sources'])) {
            $sources = array_values(array_map(fn ($s) => [
                'url'       => (string)($s['url'] ?? ''),
                'title'     => (string)($s['title'] ?? ''),
                'publisher' => (string)($s['publisher'] ?? ''),
            ], $result['sources']));
        }

        // archetypes (как вернули из валидатора)
        $archetypes = [];
        if (!empty($result['archetypes']) && is_array($result['archetypes'])) {
            $archetypes = $result['archetypes'];
        }

        // total_score (может отсутствовать)
        $totalScore = null;
        if (!empty($result['total_score']) && is_array($result['total_score'])) {
            $ts = $result['total_score'];
            if (isset($ts['min'], $ts['max']) && is_int($ts['min']) && is_int($ts['max'])) {
                $totalScore = ['min' => $ts['min'], 'max' => $ts['max']];
            }
        }

        // sections: из archetype.section или из ключей weights/category_weights
        $sectionsAgg = [];
        foreach ($archetypes as $arc) {
            $section = $arc['section'] ?? null;

            if (!$section) {
                $weights = $arc['weights'] ?? ($arc['category_weights'] ?? null);
                if (is_array($weights)) {
                    foreach (array_keys($weights) as $k) {
                        $sectionsAgg[$k] = ($sectionsAgg[$k] ?? 0) + 1;
                    }
                    continue;
                }
            }

            if ($section) {
                $sectionsAgg[$section] = ($sectionsAgg[$section] ?? 0) + 1;
            }
        }

        $sections = [];
        foreach ($sectionsAgg as $key => $count) {
            $sections[] = ['key' => (string)$key, 'archetype_count' => (int)$count];
        }

        return response()->json([
            'exam' => [
                'id'              => $exam->id,
                'slug'            => $exam->slug,
                'title'           => $exam->title,
                'research_status' => $exam->research_status,
            ],
            'sources'     => $sources,
            'archetypes'  => $archetypes,
            'sections'    => $sections,
            'total_score' => $totalScore,
            'task_id'     => $task->id,
        ]);
    }
}
