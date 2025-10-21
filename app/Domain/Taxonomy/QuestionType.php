<?php

namespace App\Domain\Taxonomy;

enum QuestionType: string
{
    case SINGLE_SELECT = 'single_select';
    case MULTI_SELECT = 'multi_select';
    case TRUE_FALSE = 'true_false';
    case YES_NO_NG = 'yes_no_ng';
    case DROPDOWN_CLOZE = 'dropdown_cloze';
    case GAP_CLOZE = 'gap_cloze';
    case BANKED_CLOZE = 'banked_cloze';
    case MATCHING = 'matching';
    case ORDER_SENTENCES = 'order_sentences';
    case ORDER_WORDS = 'order_words';
    case HIGHLIGHT_TEXT = 'highlight_text';
    case SHORT_ANSWER = 'short_answer';
    case NUMERIC = 'numeric';
    case LISTEN_MCQ = 'listen_mcq';
    case DICTATION = 'dictation';
    case ERROR_CORRECTION = 'error_correction';
    case WRITING_PROMPT = 'writing_prompt';
    case SPEAKING_PROMPT = 'speaking_prompt';

    public static function all(): array
    {
        return array_map(fn (self $e) => $e->value, self::cases());
    }
}
