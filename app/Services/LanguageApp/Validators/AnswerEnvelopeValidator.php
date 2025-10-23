<?php

namespace App\Services\LanguageApp\Validators;

use App\Domain\Taxonomy\QuestionType;
use Illuminate\Validation\ValidationException;

/**
 * Валидирует конверт ответов от FE по контракту AnswerEnvelope:
 * см. docs/questions_types.md (mini-JSON схемы).
 */
final class AnswerEnvelopeValidator
{
    public function validate(array $env): array
    {
        $type = (string) ($env['type'] ?? '');
        if (! in_array($type, QuestionType::all(), true)) {
            throw ValidationException::withMessages([
                'type' => "Unknown type '{$type}'. Allowed: ".implode(',', QuestionType::all()),
            ]);
        }

        // Быстрые проверки формы по типу (ровно как в контракте FE→API)
        switch ($type) {
            case 'single_select':
            case 'listen_mcq':
            case 'dropdown_cloze':
            case 'banked_cloze':
            case 'short_answer':
            case 'numeric':
            case 'true_false':
            case 'yes_no_ng':
            case 'gap_cloze':
                if (! isset($env['answers']) || ! is_array($env['answers'])) {
                    throw ValidationException::withMessages(['answers' => 'answers: Record is required']);
                }
                break;
            case 'multi_select':
                if (! isset($env['answers']) || ! is_array($env['answers'])) {
                    throw ValidationException::withMessages(['answers' => 'answers: Record<string,string[]> required']);
                }
                // быстрая проверка что значения — массивы строк
                foreach ($env['answers'] as $k => $v) {
                    if (! is_array($v)) {
                        throw ValidationException::withMessages(["answers.$k" => 'must be string[]']);
                    }
                }
                break;
            case 'matching':
                if (! isset($env['pairs']) || ! is_array($env['pairs']) || empty($env['pairs'])) {
                    throw ValidationException::withMessages(['pairs' => 'pairs: Array<{leftId,rightId}> required']);
                }
                break;
            case 'order_sentences':
            case 'order_words':
                if (! isset($env['order']) || ! is_array($env['order'])) {
                    throw ValidationException::withMessages(['order' => 'order: string[] required']);
                }
                break;
            case 'highlight_text':
                if (! isset($env['spans']) || ! is_array($env['spans'])) {
                    throw ValidationException::withMessages(['spans' => 'spans: Array<{start,end}> required']);
                }
                break;
            case 'dictation':
            case 'error_correction':
            case 'writing_prompt':
                if (! isset($env['text']) || ! is_string($env['text'])) {
                    throw ValidationException::withMessages(['text' => 'text: string is required']);
                }
                break;
            case 'speaking_prompt':
                if (! isset($env['audioUrl']) || ! is_string($env['audioUrl'])) {
                    throw ValidationException::withMessages(['audioUrl' => 'audioUrl: string is required']);
                }
                break;
        }

        return $env; // нормализацию оставляем скорам/бизнес-логике
    }
}
