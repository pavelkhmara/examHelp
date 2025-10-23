<?php

namespace App\Domain\Questions;

final class QuestionTypeContract
{
    /** @var string[] */
    public const WHITELIST = [
        'single_select',
        'multi_select',
        'true_false',
        'yes_no_ng',
        'dropdown_cloze',
        'gap_cloze',
        'banked_cloze',
        'matching',
        'order_sentences',
        'order_words',
        'highlight_text',
        'short_answer',
        'numeric',
        'listen_mcq',
        'dictation',
        'error_correction',
        'writing_prompt',
        'speaking_prompt',
    ];

    public static function isAllowed(string $type): bool
    {
        return in_array($type, self::WHITELIST, true);
    }

    /** Маппинг типа → режим скоринга по умолчанию: exact/partial/fuzzy/rubric */
    public static function defaultScoringMode(string $type): string
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
