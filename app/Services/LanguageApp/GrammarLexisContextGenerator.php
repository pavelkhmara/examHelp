<?php

declare(strict_types=1);

namespace App\Services\LanguageApp;

use App\Models\QuestionGroup;
use Illuminate\Support\Facades\Log;

/**
 * Grammar/Lexis Context Generator
 *
 * Генерирует короткие контекстные тексты (200-300 слов) для grammar/lexis заданий.
 *
 * В отличие от reading passages (1500-3000 слов), создаёт компактные нарративы,
 * которые естественно включают грамматические структуры для трансформаций,
 * колокаций и словообразования.
 *
 * Типы контекстов:
 * 1. NARRATIVE - личный рассказ (past tenses, reported speech)
 * 2. EMAIL - формальное/неформальное письмо (conditional, collocations)
 * 3. NEWS - новостная заметка (passive voice, formal language)
 * 4. DIALOGUE - диалог (direct → indirect speech)
 */
class GrammarLexisContextGenerator
{
    public function __construct(
        private readonly AiProvider $ai
    ) {}

    /**
     * Генерирует короткий контекст для группы grammar/lexis вопросов
     *
     * @param  QuestionGroup  $group  Группа вопросов
     * @param  string|null  $theme  Тема (опционально, будет определена автоматически)
     * @return string|null Текст контекста (200-300 слов) или null если не удалось сгенерировать
     */
    public function generateContext(QuestionGroup $group, ?string $theme = null): ?string
    {
        $exam = $group->exam;
        $section = $group->section;
        $questions = $group->questions()->orderBy('order')->get();

        if ($questions->isEmpty()) {
            Log::warning('[GrammarLexisContextGenerator] No questions in group', [
                'group_id' => $group->id,
            ]);

            return null;
        }

        Log::info('[GrammarLexisContextGenerator] Generating context', [
            'group_id' => $group->id,
            'title' => $group->title,
            'question_count' => $questions->count(),
            'theme' => $theme,
        ]);

        // Анализируем грамматические структуры в вопросах
        $grammarPoints = $this->extractGrammarPoints($questions);

        // Определяем тему если не задана
        $theme = $theme ?? $this->suggestTheme($grammarPoints, $group);

        // Определяем тип контекста
        $contextType = $this->detectContextType($grammarPoints, $group);

        // Собираем контекст для промпта
        $context = $this->buildContext($group, $questions, $grammarPoints, $theme, $contextType);

        // Генерируем через AI
        $prompt = $this->buildPrompt($context);

        try {
            $response = $this->ai->generate([
                'messages' => [
                    ['role' => 'system', 'content' => $prompt],
                ],
            ], [
                'model' => 'default',
                'json_strict' => false, // Plain HTML text
            ]);

            if (! ($response['ok'] ?? false)) {
                Log::error('[GrammarLexisContextGenerator] AI generation failed', [
                    'group_id' => $group->id,
                    'error' => $response['error'] ?? 'Unknown error',
                ]);

                return null;
            }

            $text = $response['text'] ?? '';

            // Очищаем от markdown если есть
            $text = $this->cleanText($text);

            if (empty($text)) {
                Log::warning('[GrammarLexisContextGenerator] Empty text generated', [
                    'group_id' => $group->id,
                ]);

                return null;
            }

            Log::info('[GrammarLexisContextGenerator] Context generated successfully', [
                'group_id' => $group->id,
                'text_length' => mb_strlen(strip_tags($text)),
            ]);

            return $text;

        } catch (\Exception $e) {
            Log::error('[GrammarLexisContextGenerator] Exception during generation', [
                'group_id' => $group->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Извлекает грамматические структуры из вопросов
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, \App\Models\Question>  $questions
     * @return array<string, int> Массив: grammar_point => count
     */
    protected function extractGrammarPoints($questions): array
    {
        $points = [];

        foreach ($questions as $q) {
            $instructions = $q->instructions ?? [];
            $text = $q->stimulus['text_html'] ?? '';

            $instrLower = mb_strtolower($instructions['full'] ?? $instructions['brief'] ?? '');
            $textLower = mb_strtolower(strip_tags($text));

            // Passive voice
            if (str_contains($instrLower, 'bierną') || str_contains($instrLower, 'passive')) {
                $points['passive_voice'] = ($points['passive_voice'] ?? 0) + 1;
            }

            // Conditional mood
            if (str_contains($instrLower, 'warunkow') || str_contains($instrLower, 'gdyby') ||
                str_contains($textLower, 'gdyby') || str_contains($instrLower, 'conditional')) {
                $points['conditional'] = ($points['conditional'] ?? 0) + 1;
            }

            // Participles (imiesłowy)
            if (str_contains($instrLower, 'imiesłow') || str_contains($instrLower, 'participle')) {
                $points['participles'] = ($points['participles'] ?? 0) + 1;
            }

            // Reported speech (mowa zależna)
            if (str_contains($instrLower, 'mowa zależna') || str_contains($instrLower, 'reported speech')) {
                $points['reported_speech'] = ($points['reported_speech'] ?? 0) + 1;
            }

            // Collocations (kolokacje)
            if (str_contains($instrLower, 'kolokacj') || str_contains($instrLower, 'collocation')) {
                $points['collocations'] = ($points['collocations'] ?? 0) + 1;
            }

            // Word formation (słowotwórstwo)
            if (str_contains($instrLower, 'słowotwór') || str_contains($instrLower, 'word formation') ||
                str_contains($instrLower, 'przedrostek') || str_contains($instrLower, 'prefix')) {
                $points['word_formation'] = ($points['word_formation'] ?? 0) + 1;
            }

            // Verb forms (formy czasownika)
            if (str_contains($instrLower, 'czasownik') || str_contains($instrLower, 'verb form')) {
                $points['verb_forms'] = ($points['verb_forms'] ?? 0) + 1;
            }

            // Case endings (deklinacja)
            if (str_contains($instrLower, 'deklinacj') || str_contains($instrLower, 'case') ||
                str_contains($instrLower, 'formę wyrazu')) {
                $points['case_endings'] = ($points['case_endings'] ?? 0) + 1;
            }
        }

        return $points;
    }

    /**
     * Предлагает тему на основе грамматических структур
     */
    protected function suggestTheme(array $grammarPoints, QuestionGroup $group): string
    {
        // Если в названии группы есть подсказка
        $title = mb_strtolower($group->title ?? '');

        if (str_contains($title, 'podróż') || str_contains($title, 'travel')) {
            return 'travel';
        }
        if (str_contains($title, 'praca') || str_contains($title, 'work') || str_contains($title, 'career')) {
            return 'work';
        }
        if (str_contains($title, 'edukacj') || str_contains($title, 'nauka') || str_contains($title, 'education')) {
            return 'education';
        }

        // Определяем по грамматике
        if (isset($grammarPoints['passive_voice']) || isset($grammarPoints['reported_speech'])) {
            // Passive + reported speech → новости, события
            return 'news_event';
        }

        if (isset($grammarPoints['conditional'])) {
            // Conditional → планы, гипотезы
            return 'plans_hypotheticals';
        }

        if (isset($grammarPoints['collocations'])) {
            // Collocations → работа, формальный стиль
            return 'work';
        }

        // По умолчанию
        return 'daily_life';
    }

    /**
     * Определяет тип контекста
     */
    protected function detectContextType(array $grammarPoints, QuestionGroup $group): string
    {
        $level = $group->exam->level ?? 'B1';

        // News brief - хорошо для passive voice
        if (isset($grammarPoints['passive_voice'])) {
            return 'news';
        }

        // Dialogue - хорошо для reported speech
        if (isset($grammarPoints['reported_speech'])) {
            return 'dialogue';
        }

        // Email - хорошо для collocations и formal language
        if (isset($grammarPoints['collocations']) && in_array($level, ['B2', 'C1', 'C2'])) {
            return 'email';
        }

        // Narrative - универсальный вариант
        return 'narrative';
    }

    /**
     * Собирает контекст для промпта
     */
    protected function buildContext(
        QuestionGroup $group,
        $questions,
        array $grammarPoints,
        string $theme,
        string $contextType
    ): array {
        $exam = $group->exam;

        return [
            'exam_title' => $exam->title ?? 'Exam',
            'exam_level' => $exam->level ?? 'B1',
            'language' => $exam->language_of_test ?? 'pl',
            'group_title' => $group->title ?? 'Grammar/Lexis Task',
            'theme' => $theme,
            'context_type' => $contextType,
            'question_count' => $questions->count(),
            'grammar_points' => $grammarPoints,
            'target_words' => 250, // 200-300 words
        ];
    }

    /**
     * Строит промпт для генерации контекста
     */
    protected function buildPrompt(array $context): string
    {
        $language = $context['language'];
        $level = $context['exam_level'];
        $theme = $context['theme'];
        $contextType = $context['context_type'];
        $targetWords = $context['target_words'];
        $grammarPoints = $context['grammar_points'];

        // Описание темы
        $themeDescriptions = [
            'travel' => 'podróż lub przeprowadzka (travel or relocation)',
            'work' => 'praca, kariera, spotkania biznesowe (work, career, business meetings)',
            'education' => 'edukacja, nauka, uniwersytet (education, studying, university)',
            'daily_life' => 'codzienne życie, rutyna (daily life, routine)',
            'news_event' => 'wydarzenia, wiadomości (events, news)',
            'plans_hypotheticals' => 'plany, hipotetyczne sytuacje (plans, hypothetical situations)',
        ];

        $themeDesc = $themeDescriptions[$theme] ?? 'życie codzienne (daily life)';

        // Описание типа контекста
        $contextTypeInstructions = match ($contextType) {
            'narrative' => <<<'EOT'
Write a personal narrative (first-person story).
- Use past tenses and progressive forms
- Include natural transitions between events
- May include thoughts, feelings, and reflections
- Conversational but grammatically correct
EOT,
            'email' => <<<'EOT'
Write a formal or semi-formal email.
- Use appropriate opening and closing
- Professional tone with polite language
- Include specific requests or explanations
- Use formal collocations naturally
EOT,
            'news' => <<<'EOT'
Write a brief news article or announcement.
- Use third-person, objective tone
- Include passive voice constructions naturally
- Report events factually
- Use formal register
EOT,
            'dialogue' => <<<'EOT'
Write a dialogue between two or more people.
- Use direct speech (quotes)
- Natural conversational flow
- Include questions and responses
- May include indirect speech reporting
EOT,
            default => 'Write a coherent, natural text.',
        };

        // Список грамматических структур
        $grammarList = [];
        foreach ($grammarPoints as $point => $count) {
            $grammarList[] = "- $point ($count occurrences needed)";
        }
        $grammarListText = implode("\n", $grammarList);

        return <<<EOT
# Task: Generate Grammar/Lexis Practice Context

You are creating a SHORT coherent text for grammar and vocabulary practice.

## Context Details
- **Language**: $language
- **Level**: $level (CEFR)
- **Theme**: $themeDesc
- **Length**: Approximately $targetWords words (200-300 words)

## Text Type
$contextTypeInstructions

## Grammar Structures to Include

The text must NATURALLY incorporate these grammatical structures:

$grammarListText

**CRITICAL**: These grammar points should appear organically in the text, not forced. The text should be coherent and natural, with grammar structures embedded in realistic contexts.

## Requirements

1. **Length**: $targetWords words (±50)
2. **CEFR Level**: Match $level vocabulary and complexity
3. **Coherence**: Single unified narrative/text, not disconnected sentences
4. **Natural Language**: Authentic $language, not textbook-like
5. **Grammar Integration**: All listed structures appear naturally
6. **HTML Format**: Use <p> tags for paragraphs, <strong> for emphasis if needed

## Output Format

Return ONLY the text in clean HTML:
- Use <p> tags for paragraphs (3-4 paragraphs)
- NO markdown formatting
- NO meta-commentary
- NO explanations

## Example Structure (Narrative)

<p>Opening paragraph establishing situation...</p>
<p>Main event/development with grammar structures...</p>
<p>Consequences or reflections...</p>
<p>Conclusion tying it together...</p>

Now generate the text in $language:
EOT;
    }

    /**
     * Очищает текст от markdown
     */
    protected function cleanText(string $text): string
    {
        // Убираем markdown code blocks
        $text = preg_replace('/```[\w]*\n?/', '', $text);

        // Убираем лишние переносы строк
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Убираем пробелы в начале и конце
        $text = trim($text);

        return $text;
    }
}
