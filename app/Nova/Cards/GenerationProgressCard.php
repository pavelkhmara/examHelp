<?php

namespace App\Nova\Cards;

use App\Models\Exam;
use App\Models\GenerationPlan;
use Laravel\Nova\Card;

class GenerationProgressCard extends Card
{
    public function __construct(private Exam $exam)
    {
        parent::__construct();
    }

    public function component()
    {
        return 'generation-progress-card';
    }

    public function jsonSerialize(): array
    {
        $plans = GenerationPlan::where('exam_id', $this->exam->id)
            ->with('section')
            ->get();

        $plansData = $plans->map(function ($plan) {
            return [
                'id' => $plan->id,
                'section_name' => $plan->section->name ?? 'Unknown',
                'status' => $plan->status,
                'generated_count' => $plan->generated_count ?? 0,
                'expected_count' => count($plan->unit_slots ?? []),
            ];
        });

        $totalGenerated = $plans->sum('generated_count');
        $totalExpected = $plans->sum(fn ($p) => count($p->unit_slots ?? []));
        $progress = $totalExpected > 0 ? round(($totalGenerated / $totalExpected) * 100) : 0;

        return array_merge(parent::jsonSerialize(), [
            'plans' => $plansData,
            'total_generated' => $totalGenerated,
            'total_expected' => $totalExpected,
            'progress' => $progress,
        ]);
    }
}
