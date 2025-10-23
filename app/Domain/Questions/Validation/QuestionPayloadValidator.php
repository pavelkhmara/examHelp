<?php

declare(strict_types=1);

namespace App\Domain\Questions\Validation;

use App\Support\LiteValidationException;
use Illuminate\Validation\ValidationException;

final class QuestionPayloadValidator
{
    /** Белый список типов (из docs/questions_types.md) */
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

    // public static function validate(string $type, array $payload): array
    // {
    //     // Минимальные допустимые формы для пример-заданий
    //     switch ($type) {
    //         case 'short_answer':
    //             // допускаем либо answer (строка), либо answer_key/patterns
    //             if (
    //                 (!isset($payload['answer']) || trim((string)$payload['answer']) === '') &&
    //                 empty($payload['answer_key']) &&
    //                 empty($payload['patterns'])
    //             ) {
    //                 throw self::ve(['payload.answer' => 'short_answer requires answer or answer_key/patterns']);
    //             }
    //             return $payload;

    //         default:
    //             return $payload; // прочие типы — как есть (валидация на уровне контракта/контроллера)
    //     }
    // }

    // private static function ve(array $messages): LiteValidationException
    // {
    //     return new LiteValidationException($messages);
    // }

    /**
     * Строгая проверка payload по типу; бросает ValidationException
     *
     * @param  array<string,mixed>  $payload
     *
     * @throws ValidationException
     */
    public static function validate(string $type, array $payload): void
    {
        if (! in_array($type, self::WHITELIST, true)) {
            // было: ValidationException::withMessages([...])
            throw new LiteValidationException([
                'type' => ["Unknown question type '{$type}' (not in whitelist)."],
            ]);
        }

        switch ($type) {
            case 'single_select':
                // options: [{id,text}], answer: string(id)
                self::requireKeys($payload, ['options', 'answer']);
                self::assertOptionsArray($payload['options'], 'payload.options');
                self::assertString($payload['answer'], 'payload.answer');
                $ids = array_map(fn ($o) => (string) $o['id'], $payload['options']);
                if (! in_array((string) $payload['answer'], $ids, true)) {
                    throw self::ve(['payload.answer' => ['answer must be one of option ids']]);
                }
                break;

            case 'multi_select':
                // options: [{id,text}], answers: string[] (ids)
                self::requireKeys($payload, ['options', 'answers']);
                self::assertOptionsArray($payload['options'], 'payload.options');
                self::assertArrayOfStrings($payload['answers'], 'payload.answers', false);
                $ids = array_map(fn ($o) => (string) $o['id'], $payload['options']);
                foreach ($payload['answers'] as $i => $ans) {
                    if (! in_array((string) $ans, $ids, true)) {
                        throw self::ve(["payload.answers.{$i}" => ['must be one of option ids']]);
                    }
                }
                break;

            case 'true_false':
                // answer: 'true'|'false'
                self::requireKeys($payload, ['answer']);
                self::assertString($payload['answer'], 'payload.answer');
                if (! in_array($payload['answer'], ['true', 'false'], true)) {
                    throw self::ve(['payload.answer' => ["must be 'true' or 'false'"]]);
                }
                break;

            case 'highlight_text':
                // spans: [{start:int>=0, end:int>start}]
                self::requireKeys($payload, ['spans']);
                self::assertArray($payload['spans'], 'payload.spans', false);
                foreach ($payload['spans'] as $i => $span) {
                    if (! is_array($span)) {
                        throw self::ve(["payload.spans.{$i}" => ['must be object {start,end}']]);
                    }
                    $start = $span['start'] ?? null;
                    $end = $span['end'] ?? null;
                    if (! is_int($start) || $start < 0) {
                        throw self::ve(["payload.spans.{$i}.start" => ['must be int >= 0']]);
                    }
                    if (! is_int($end) || $end <= $start) {
                        throw self::ve(["payload.spans.{$i}.end" => ['must be int > start']]);
                    }
                }
                break;

            case 'order_words':
            case 'order_sentences':
                // order: string[]
                self::requireKeys($payload, ['order']);
                self::assertArrayOfStrings($payload['order'], 'payload.order', false);
                break;

            case 'short_answer':
                // answers: array of strings (values важны; ключи можем игнорить)
                if (array_key_exists('answers', $payload)) {
                    self::assertArrayOfStrings($payload['answers'], 'payload.answers', false);
                }
                break;

            case 'numeric':
                // answers: array of numeric values
                if (array_key_exists('answers', $payload)) {
                    self::assertArrayOfNumbers($payload['answers'], 'payload.answers', false);
                }
                break;

                // Остальные типы валидируем мягко — присутствие payload как массива достаточно.
            default:
                // Ничего обязательного — допускаем любой payload-мэп.
                break;
        }
    }

    /** @param array<string,mixed> $payload */
    private static function requireKeys(array $payload, array $keys): void
    {
        foreach ($keys as $k) {
            if (! array_key_exists($k, $payload)) {
                throw self::ve(["payload.{$k}" => ["Missing required field '{$k}'"]]);
            }
        }
    }

    /** @throws ValidationException */
    private static function assertOptionsArray(mixed $value, string $path): void
    {
        if (! is_array($value) || count($value) < 2) {
            throw self::ve([$path => ['must be array with at least 2 options']]);
        }
        foreach ($value as $i => $opt) {
            if (! is_array($opt) || ! isset($opt['id']) || ! is_string($opt['id']) || $opt['id'] === '') {
                throw self::ve(["{$path}.{$i}.id" => ['must be non-empty string']]);
            }
            if (! isset($opt['text']) || ! is_string($opt['text']) || $opt['text'] === '') {
                throw self::ve(["{$path}.{$i}.text" => ['must be non-empty string']]);
            }
        }
    }

    /** @throws ValidationException */
    private static function assertArrayOfStrings(mixed $value, string $path, bool $allowEmpty = true): void
    {
        if (! is_array($value)) {
            throw self::ve([$path => ['must be array of strings']]);
        }
        if (! $allowEmpty && $value === []) {
            throw self::ve([$path => ['must not be empty']]);
        }
        foreach ($value as $i => $v) {
            if (! is_string($v)) {
                // допускаем ассоц.массивы с string-значениями
                if (! is_scalar($v)) {
                    throw self::ve(["{$path}.{$i}" => ['must be string']]);
                }
                // приведём к строке, но валидатор только проверяет — преобразование оставим вызывающему коду
            }
        }
    }

    /** @throws ValidationException */
    private static function assertArrayOfNumbers(mixed $value, string $path, bool $allowEmpty = true): void
    {
        if (! is_array($value)) {
            throw self::ve([$path => ['must be array of numbers']]);
        }
        if (! $allowEmpty && $value === []) {
            throw self::ve([$path => ['must not be empty']]);
        }
        foreach ($value as $i => $v) {
            if (! is_int($v) && ! is_float($v) && ! (is_string($v) && is_numeric($v))) {
                throw self::ve(["{$path}.{$i}" => ['must be numeric (int|float|string numeric)']]);
            }
        }
    }

    /** @throws ValidationException */
    private static function assertArray(mixed $value, string $path, bool $allowEmpty = true): void
    {
        if (! is_array($value)) {
            throw self::ve([$path => ['must be array']]);
        }
        if (! $allowEmpty && $value === []) {
            throw self::ve([$path => ['must not be empty']]);
        }
    }

    /** @throws ValidationException */
    private static function assertString(mixed $value, string $path): void
    {
        if (! is_string($value) || $value === '') {
            throw self::ve([$path => ['must be non-empty string']]);
        }
    }

    /** Удобный конструктор ValidationException */
    private static function ve(array $messages): LiteValidationException
    {
        return new LiteValidationException($messages);
    }
}
