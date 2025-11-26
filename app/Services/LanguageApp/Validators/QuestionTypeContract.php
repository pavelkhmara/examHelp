<?php

namespace App\Services\LanguageApp\Validators;

use App\Domain\Scoring\Adapters\ExactScoringAdapter;
use App\Domain\Scoring\Adapters\FuzzyScoringAdapter;
use App\Domain\Scoring\Adapters\PartialScoringAdapter;
use App\Domain\Scoring\Adapters\RegexScoringAdapter;
use App\Domain\Scoring\Adapters\RubricScoringAdapter;
use App\Domain\Taxonomy\QuestionType;
use Illuminate\Validation\ValidationException;

final class QuestionTypeContract
{
    /** Allowed scoring modes per question type (from docs/questions_types.md) */
    private const ALLOWED = [
        'single_select' => ['exact'],
        'multi_select' => ['exact', 'partial'],
        'true_false' => ['exact'],
        'yes_no_ng' => ['exact'],
        'dropdown_cloze' => ['exact'],
        'gap_cloze' => ['fuzzy', 'regex', 'exact'],
        'banked_cloze' => ['exact'],
        'matching' => ['exact', 'partial'],
        'order_sentences' => ['exact', 'partial'],
        'order_words' => ['exact', 'partial'],
        'highlight_text' => ['exact', 'partial'],
        'short_answer' => ['fuzzy', 'regex', 'exact'],
        'numeric' => ['exact'],
        'listen_mcq' => ['exact'],
        'dictation' => ['fuzzy', 'regex'],
        'error_correction' => ['rubric', 'fuzzy', 'regex'],
        'writing_prompt' => ['rubric'],
        'speaking_prompt' => ['rubric'],

        // ✅ NEW: Listening variants (2025-11-26)
        'listen_true_false' => ['exact'],
        'listen_yes_no_ng' => ['exact'],

        // ✅ NEW: Additional types (2025-11-26)
        'translation' => ['rubric', 'fuzzy'],  // Перевод: rubric для общей оценки, fuzzy для точности
        'roleplay' => ['rubric'],              // Ролевая игра: только rubric (коммуникативные навыки)
        'note_completion' => ['exact', 'partial'],  // Заполнение заметок: exact или partial credit
    ];

    private array $adapters;

    public function __construct()
    {
        $this->adapters = [
            'exact' => new ExactScoringAdapter,
            'partial' => new PartialScoringAdapter,
            'fuzzy' => new FuzzyScoringAdapter,
            'regex' => new RegexScoringAdapter,
            'rubric' => new RubricScoringAdapter,
        ];
    }

    /** Validate one task block: {type, items[], scoring{mode,...}} */
    public function validateTask(array $task): array
    {
        $type = (string) ($task['type'] ?? '');
        if (! in_array($type, QuestionType::all(), true)) {
            throw ValidationException::withMessages([
                'type' => "Unknown question type: '{$type}'. Allowed: ".implode(',', QuestionType::all()),
            ]);
        }

        $items = $task['items'] ?? null;
        $scoring = $task['scoring'] ?? null;
        if (! is_array($items) || empty($items)) {
            throw ValidationException::withMessages(['items' => 'items[] is required and must be non-empty array']);
        }
        if (! is_array($scoring) || empty($scoring['mode'])) {
            throw ValidationException::withMessages(['scoring.mode' => 'scoring.mode is required']);
        }

        $mode = (string) $scoring['mode'];
        if (! in_array($mode, self::ALLOWED[$type] ?? [], true)) {
            throw ValidationException::withMessages([
                'scoring.mode' => "Mode '{$mode}' is not allowed for type '{$type}'. Allowed: ".implode(',', self::ALLOWED[$type] ?? []),
            ]);
        }

        // mode-level config check
        $this->adapters[$mode]->validateConfig($scoring);

        // lightweight per-type payload checks (does NOT duplicate deep schema)
        foreach ($items as $idx => $it) {
            $this->validateItemPayload($type, $it, $idx);
        }

        // Return normalized projection (safe for FE)
        return [
            'type' => $type,
            'items' => array_values($items),
            'scoring' => $scoring,
            'metadata' => $task['metadata'] ?? [],
        ];
    }

    private function validateItemPayload(string $type, array $item, int $idx): void
    {
        $path = "items[$idx]";
        $idOk = isset($item['id']) && is_string($item['id']);
        if (! $idOk) {
            throw ValidationException::withMessages(["$path.id" => 'item.id is required (string)']);
        }
        if (empty($item['prompt'])) {
            throw ValidationException::withMessages(["$path.prompt" => 'item.prompt is required']);
        }

        $needOptions = in_array($type, ['single_select', 'multi_select', 'listen_mcq', 'dropdown_cloze'], true);
        if ($needOptions) {
            if (! isset($item['options']) || ! is_array($item['options']) || count($item['options']) < 2) {
                throw ValidationException::withMessages(["$path.options" => 'options[] (>=2) is required']);
            }
            foreach ($item['options'] as $opt) {
                if (! isset($opt['id'],$opt['label'])) {
                    throw ValidationException::withMessages(["$path.options" => 'each option must have idlabel']);
                }
            }
        }

        // type-specific shapes (minimal but strict)
        switch ($type) {
            case 'matching':
                if (empty($item['pairs']) || ! is_array($item['pairs'])) {
                    throw ValidationException::withMessages(["$path.pairs" => 'pairs[] is required for matching']);
                }
                break;
            case 'order_sentences':
            case 'order_words':
                if (empty($item['order']) || ! is_array($item['order'])) {
                    throw ValidationException::withMessages(["$path.order" => 'order[] is required']);
                }
                break;
            case 'highlight_text':
                if (empty($item['spans']) || ! is_array($item['spans'])) {
                    throw ValidationException::withMessages(["$path.spans" => 'spans[] {start,end} required']);
                }
                break;
            case 'numeric':
                // nothing on item-level; validated via scoring answer_key
                break;
            case 'gap_cloze':
            case 'banked_cloze':
                if (empty($item['slots']) || ! is_array($item['slots'])) {
                    throw ValidationException::withMessages(["$path.slots" => 'slots[] is required for cloze']);
                }
                break;
        }
    }

    /**
     * Возвращает whitelist всех допустимых типов вопросов из enum QuestionType.
     *
     * @return array<int,string>
     */
    public static function allowedTypes(): array
    {
        // Поддерживаем два варианта реализации enum-сущности: custom ::all() или native ::cases()
        if (method_exists(\App\Domain\Taxonomy\QuestionType::class, 'all')) {
            /** @phpstan-ignore-next-line */
            return \App\Domain\Taxonomy\QuestionType::all();
        }

        // PHP 8.1+ backed enum: соберём значения cases()
        return array_map(
            static fn ($c) => $c->value,
            \App\Domain\Taxonomy\QuestionType::cases()
        );
    }

    /**
     * Проверяет, что тип из whitelist. Нейтрализует null/пустые строки.
     */
    public static function isKnownType(?string $type): bool
    {
        if ($type === null || $type === '') {
            return false;
        }

        return in_array($type, self::allowedTypes(), true);
    }
}
