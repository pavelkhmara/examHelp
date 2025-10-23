<?php

namespace App\Domain\Scoring\Adapters;

use App\Domain\Scoring\Contracts\ScoringAdapter;
use App\Domain\Scoring\Score;

final class ExactScoringAdapter implements ScoringAdapter
{
    public function validateConfig(array $scoring): void
    {
        // exact — без обязательных полей
    }

    public function score(array $task, mixed $userAnswer): Score
    {
        // поддержим два распространенных кейса:
        // 1) single_select: options[ {id,is_correct} ], userAnswer['selected_option_id']
        // 2) exact текст/число: answer_key в $task['answer_key'] против userAnswer['text'] | ['value']
        $type = (string) ($task['type'] ?? '');
        $total = 1;
        $got = 0;

        if (in_array($type, ['single_select', 'true_false', 'yes_no_ng', 'dropdown_cloze', 'banked_cloze'], true)) {
            $selected = $userAnswer['selected_option_id'] ?? null;
            $correctId = null;

            // поиск правильного варианта (options с is_correct)
            foreach ((array) ($task['options'] ?? $task['items'][0]['options'] ?? []) as $opt) {
                if (! empty($opt['is_correct'])) {
                    $correctId = $opt['id'] ?? null;
                    break;
                }
            }
            $got = ($selected !== null && $correctId !== null && (string) $selected === (string) $correctId) ? 1 : 0;

            return new Score($total, $got, true);
        }

        // exact для короткого ответа
        $expected = $task['answer_key'] ?? $task['items'][0]['answer_key'] ?? null;
        $ua = is_array($userAnswer) ? ($userAnswer['text'] ?? $userAnswer['value'] ?? null) : $userAnswer;
        if ($expected !== null && $ua !== null && trim((string) $expected) === trim((string) $ua)) {
            $got = 1;
        }

        return new Score($total, $got, true);
    }
}
