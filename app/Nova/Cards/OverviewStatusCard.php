<?php

namespace App\Nova\Cards;

use App\Models\Exam;
use Laravel\Nova\Card;

class OverviewStatusCard extends Card
{
    public function __construct(private Exam $exam)
    {
        parent::__construct();
    }

    public function component()
    {
        return 'overview-status-card';
    }

    public function jsonSerialize(): array
    {
        $structure = $this->exam->meta['structure_v2'] ?? null;

        $phaseA = [
            'completed' => ! empty($structure),
            'sections_count' => count($structure['sections'] ?? []),
            'generated_at' => $structure['generated_at'] ?? null,
        ];

        /** @var array<int|string, mixed> $sections */
        $sections = $structure['sections'] ?? [];

        $phaseB = [
            'completed' => ! empty($structure['sections'][0]['assembly'] ?? null),
            'assembly_mode' => $structure['sections'][0]['assembly']['mode'] ?? null,
            'questions_count' => collect($sections)
                ->sum(fn (array $s) => count($s['question_archetypes'] ?? $s['questions'] ?? [])),
        ];

        return array_merge(parent::jsonSerialize(), [
            'phaseA' => $phaseA,
            'phaseB' => $phaseB,
        ]);
    }
}
