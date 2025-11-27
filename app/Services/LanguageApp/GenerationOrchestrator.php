<?php

declare(strict_types=1);

namespace App\Services\LanguageApp;

use App\Models\GenerationPlan;
use Illuminate\Support\Facades\Log;

class GenerationOrchestrator
{
    public function __construct(
        private readonly QuestionSynthesizer $synthesizer,
        private readonly QuestionValidator $validator,
        private readonly QuestionDeduplicator $deduplicator,
        private readonly QuestionAttacher $attacher,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function runFullPipeline(GenerationPlan $plan): array
    {
        $exam = $plan->exam()->firstOrFail();

        Log::info('[GenerationOrchestrator] Pipeline start', [
            'plan_id' => $plan->id,
            'section_id' => $plan->section_id,
        ]);

        $questions = $this->synthesizer->synthesize($plan, $exam);

        $validated = $this->validator->validateAndFinalize($questions, $plan, $exam);

        $deduped = $this->deduplicator->detectDuplicates($validated, $exam);

        $this->attacher->attachToExam($deduped, $plan, $exam);

        Log::info('[GenerationOrchestrator] Pipeline completed', [
            'plan_id' => $plan->id,
            'section_id' => $plan->section_id,
            'generated_count' => count($deduped),
        ]);

        return $deduped;
    }
}
