<?php
declare(strict_types=1);

namespace App\Domain\Scoring;

use App\Domain\Scoring\Adapters\ExactScoringAdapter;
use App\Domain\Scoring\Adapters\FuzzyScoringAdapter;
use App\Domain\Scoring\Adapters\PartialScoringAdapter;
use App\Domain\Scoring\Adapters\RubricScoringAdapter;

final class ScoringEngine
{
    private ExactScoringAdapter $exact;
    private PartialScoringAdapter $partial;
    private FuzzyScoringAdapter $fuzzy;
    private RubricScoringAdapter $rubric;

    public function __construct()
    {
        $this->exact   = new ExactScoringAdapter();
        $this->partial = new PartialScoringAdapter();
        $this->fuzzy   = new FuzzyScoringAdapter();
        $this->rubric  = new RubricScoringAdapter();
    }

    /**
     * @param array<string,mixed> $payload
     * @param mixed $userAnswer
     */
    public function score(string $type, array $payload, mixed $userAnswer): Score
    {
        $mode = $this->modeByType($type);

        return match ($mode) {
            'exact'   => $this->exact->score($payload, $userAnswer),
            'partial' => $this->partial->score(array_merge($payload, ['type' => $type]), $userAnswer),
            'fuzzy'   => $this->fuzzy->score($payload, $userAnswer),
            'rubric'  => $this->rubric->score($payload, $userAnswer),
            default   => $this->exact->score($payload, $userAnswer),
        };
    }

    private function modeByType(string $type): string
    {
        return match ($type) {
            'single_select', 'true_false', 'yes_no_ng', 'numeric', 'listen_mcq' => 'exact',
            'multi_select', 'matching', 'order_sentences', 'order_words', 'highlight_text' => 'partial',
            'dropdown_cloze', 'gap_cloze', 'short_answer', 'dictation', 'error_correction' => 'fuzzy',
            'writing_prompt', 'speaking_prompt' => 'rubric',
            default => 'exact',
        };
    }
}
