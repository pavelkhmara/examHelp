<?php

declare(strict_types=1);

namespace App\Services\LanguageApp;

use App\Models\QuestionGroup;
use Illuminate\Support\Facades\Log;

/**
 * Reading Stimulus Generator
 *
 * Генерирует текстовые passages для reading заданий через AI.
 *
 * Типы passages:
 * 1. ARTICLE - публицистические статьи (journalistic articles)
 * 2. ACADEMIC - академические тексты (lectures, research)
 * 3. REPORT - отчёты, доклады (formal reports)
 * 4. LETTER - письма (formal/informal letters)
 * 5. MIXED - смешанные короткие тексты (announcements, instructions)
 *
 * Passage должен:
 * - Соответствовать уровню экзамена (A1-C2)
 * - Содержать информацию, необходимую для ответа на вопросы
 * - Быть связным и естественным для письменной речи
 * - Иметь достаточную длину (1500-3000 слов для unified)
 */
class ReadingStimulusGenerator
{
    public function __construct(
        private readonly AiProvider $ai
    ) {}

    /**
     * Генерирует reading passage для группы вопросов
     *
     * @param  QuestionGroup  $group  Группа вопросов
     * @param  string  $passageType  'article'|'academic'|'report'|'letter'|'mixed'
     * @return string|null Текст passage или null если не удалось сгенерировать
     */
    public function generatePassage(QuestionGroup $group, string $passageType): ?string
    {
        $exam = $group->exam;
        $section = $group->section;
        $questions = $group->questions()->orderBy('order')->get();

        if ($questions->isEmpty()) {
            Log::warning('[ReadingStimulusGenerator] No questions in group', [
                'group_id' => $group->id,
            ]);

            return null;
        }

        Log::info('[ReadingStimulusGenerator] Generating passage', [
            'group_id' => $group->id,
            'title' => $group->title,
            'passage_type' => $passageType,
            'question_count' => $questions->count(),
        ]);

        // Собираем контекст для промпта
        $context = $this->buildContext($group, $questions, $passageType);

        // Генерируем через AI
        $prompt = $this->buildPrompt($context, $passageType);

        try {
            $response = $this->ai->generate([
                'messages' => [
                    ['role' => 'system', 'content' => $prompt],
                ],
            ], [
                'model' => 'default', // Используем основную модель
                'json_strict' => false, // Override global json_strict - we need plain HTML text
            ]);

            if (! ($response['ok'] ?? false)) {
                Log::error('[ReadingStimulusGenerator] AI generation failed', [
                    'group_id' => $group->id,
                    'error' => $response['error'] ?? 'Unknown error',
                ]);

                return null;
            }

            $passage = $response['text'] ?? '';

            // Очищаем от markdown если есть
            $passage = $this->cleanPassage($passage);

            if (empty($passage)) {
                Log::warning('[ReadingStimulusGenerator] Empty passage generated', [
                    'group_id' => $group->id,
                ]);

                return null;
            }

            Log::info('[ReadingStimulusGenerator] Passage generated successfully', [
                'group_id' => $group->id,
                'passage_length' => mb_strlen($passage),
            ]);

            return $passage;

        } catch (\Exception $e) {
            Log::error('[ReadingStimulusGenerator] Exception during generation', [
                'group_id' => $group->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Собирает контекст для генерации
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, \App\Models\Question>  $questions
     */
    protected function buildContext(QuestionGroup $group, $questions, string $passageType): array
    {
        $exam = $group->exam;
        $section = $group->section;
        $stimulus = $group->stimulus ?? [];
        $metadata = $group->metadata ?? [];

        // Извлекаем информацию о вопросах
        $questionsInfo = [];
        foreach ($questions as $q) {
            $qInfo = [
                'type' => $q->type,
                'instructions' => $q->instructions['full'] ?? $q->instructions['brief'] ?? null,
            ];

            // Добавляем options для MCQ
            $interaction = $q->interaction ?? [];
            if (! empty($interaction['options'])) {
                /** @var array<int|string, mixed> $options */
                $options = $interaction['options'];
                /** @var \Illuminate\Support\Collection<int|string, array<string, mixed>> $optionsCollection */
                $optionsCollection = collect($options);
                $qInfo['options'] = $optionsCollection
                    ->pluck('text')
                    ->toArray();
            }

            // Добавляем правильный ответ если есть
            $response = $q->response ?? [];
            if (! empty($response['correct_answer'])) {
                $qInfo['correct_answer'] = $response['correct_answer'];
            }

            $questionsInfo[] = $qInfo;
        }

        // Определяем тему из названия группы или метаданных
        $topic = $metadata['topic'] ?? $group->title ?? 'General topic';

        return [
            'exam_title' => $exam->title ?? 'Exam',
            'exam_level' => $exam->level ?? 'B2',
            'exam_language' => $exam->language_of_test ?? 'en',
            'group_title' => $group->title ?? 'Reading passage',
            'topic' => $topic,
            'question_count' => $questions->count(),
            'questions' => $questionsInfo,
            'suggested_duration_sec' => $metadata['suggested_time_sec'] ?? 600, // 10 min default
        ];
    }

    /**
     * Строит промпт для генерации
     */
    protected function buildPrompt(array $context, string $passageType): string
    {
        $examTitle = $context['exam_title'];
        $level = $context['exam_level'];
        $language = $context['exam_language'];
        $groupTitle = $context['group_title'];
        $topic = $context['topic'];
        $duration = $context['suggested_duration_sec'];
        $questionCount = $context['question_count'];
        $questionsJson = json_encode($context['questions'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Рассчитываем примерное количество слов
        // Reading speed: ~200-250 words/min for comprehension
        // For 10 min → ~2000 words target
        $targetWords = (int) ($duration / 60 * 200);
        $targetWords = max(500, min(3000, $targetWords)); // ограничиваем 500-3000

        if ($passageType === 'mixed') {
            return $this->buildMixedPrompt($context, $targetWords);
        }

        return $this->buildUnifiedPrompt($context, $targetWords, $passageType);
    }

    /**
     * Промпт для unified passage (article, academic, report, letter)
     */
    protected function buildUnifiedPrompt(array $context, int $targetWords, string $passageType): string
    {
        $examTitle = $context['exam_title'];
        $level = $context['exam_level'];
        $language = $context['exam_language'];
        $groupTitle = $context['group_title'];
        $topic = $context['topic'];
        $questionCount = $context['question_count'];
        $questionsJson = json_encode($context['questions'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Определяем тип контента
        $titleLower = strtolower($groupTitle);

        if (str_contains($titleLower, 'article') || str_contains($titleLower, 'artykuł') ||
            str_contains($titleLower, 'publicystyczny') || str_contains($titleLower, 'journalistic')) {
            $contentType = 'article';
        } elseif (str_contains($titleLower, 'academic') || str_contains($titleLower, 'lecture') ||
                  str_contains($titleLower, 'research') || str_contains($titleLower, 'naukowy')) {
            $contentType = 'academic';
        } elseif (str_contains($titleLower, 'report') || str_contains($titleLower, 'raport')) {
            $contentType = 'report';
        } elseif (str_contains($titleLower, 'letter') || str_contains($titleLower, 'list')) {
            $contentType = 'letter';
        } else {
            $contentType = $passageType; // fallback to parameter
        }

        $contentTypeInstructions = match ($contentType) {
            'article' => <<<'EOT'
Write a journalistic article (публицистическая статья).
- Use engaging, informative style appropriate for newspapers/magazines
- Include introduction, body with 3-5 main points, and conclusion
- Use transitional phrases and cohesive devices
- May include quotes, statistics, or expert opinions
EOT,
            'academic' => <<<'EOT'
Write an academic text or lecture excerpt.
- Use formal, scholarly language appropriate for university level
- Include clear thesis, supporting evidence, and analysis
- Use academic vocabulary and complex sentence structures
- May include research findings, theories, or case studies
EOT,
            'report' => <<<'EOT'
Write a formal report or study document.
- Use objective, professional language
- Include executive summary, findings, and recommendations
- Use bullet points, numbered lists, or section headings where appropriate
- Focus on factual information and data
EOT,
            'letter' => <<<'EOT'
Write a formal or semi-formal letter.
- Use appropriate letter format and salutations
- Include clear purpose, main body, and closing
- Use polite, professional tone
- Address specific points or requests
EOT,
            default => <<<'EOT'
Write a cohesive, well-structured text.
- Use clear, engaging language
- Include introduction, main content, and conclusion
- Use appropriate vocabulary and grammar for the level
EOT,
        };

        return <<<EOT
# Task: Generate Reading Passage

You are creating a reading comprehension passage for a language exam.

## Exam Context
- **Exam**: {$examTitle}
- **Level**: {$level} (CEFR)
- **Language**: {$language}
- **Task**: {$groupTitle}
- **Topic**: {$topic}

## Requirements

1. **Length**: Approximately {$targetWords} words (suitable for reading comprehension)

2. **Content Type**: {$contentType}
{$contentTypeInstructions}

3. **Language Level**: Match {$level} CEFR level
   - For A1-A2: Simple sentences, basic vocabulary, short paragraphs
   - For B1-B2: Moderate complexity, varied vocabulary, standard academic style
   - For C1-C2: Complex structures, sophisticated vocabulary, nuanced arguments

4. **Must Cover**: The passage MUST contain information that allows answering ALL these questions:

```json
{$questionsJson}
```

**CRITICAL**: Analyze each question's options and correct answers. The passage must explicitly or implicitly contain information that makes the correct answer clearly identifiable while making wrong answers plausible but incorrect.

5. **Written Style**:
   - Use natural written language appropriate for the content type
   - Include cohesive devices (however, moreover, in addition, etc.)
   - Use varied sentence structures
   - Organize content logically with clear paragraphs

## Output Format

Return ONLY the passage text in clean HTML format:
- Use `<p>` tags for paragraphs
- Use `<h2>` or `<h3>` for section headings if appropriate
- Use `<strong>` for emphasis where needed
- NO markdown formatting
- NO meta-commentary
- NO instructions or explanations

## Example Structure for Article

<h2>Title</h2>
<p>Introduction paragraph establishing context and main theme...</p>
<p>First main point with supporting details...</p>
<p>Second main point with examples...</p>
<p>Third main point with analysis...</p>
<p>Conclusion tying together main ideas...</p>

## Example Structure for Academic Text

<h2>Topic Title</h2>
<p>Introduction with thesis statement...</p>
<p>Background and context...</p>
<p>Main argument with evidence...</p>
<p>Supporting analysis...</p>
<p>Implications and conclusions...</p>

Now generate the passage in {$language}:
EOT;
    }

    /**
     * Промпт для mixed passage (короткие тексты)
     */
    protected function buildMixedPrompt(array $context, int $targetWords): string
    {
        $examTitle = $context['exam_title'];
        $level = $context['exam_level'];
        $language = $context['exam_language'];
        $groupTitle = $context['group_title'];
        $questionCount = $context['question_count'];
        $questionsJson = json_encode($context['questions'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $wordsPerFragment = (int) ($targetWords / $questionCount);
        $wordsPerFragment = max(50, min(300, $wordsPerFragment));

        return <<<EOT
# Task: Generate Multiple Short Reading Texts

You are creating a collection of short reading texts for a language exam.

## Exam Context
- **Exam**: {$examTitle}
- **Level**: {$level} (CEFR)
- **Language**: {$language}
- **Task**: {$groupTitle}

## Requirements

1. **Structure**: Generate {$questionCount} separate short texts
   - Each text: approximately {$wordsPerFragment} words
   - Texts are independent (different topics/sources)

2. **Text Types** (vary these):
   - Announcement or notice
   - Advertisement
   - News brief
   - Email or message
   - Sign or instruction
   - Review or testimonial

3. **Language Level**: Match {$level} CEFR level

4. **Must Cover**: Each text must allow answering its corresponding question:

```json
{$questionsJson}
```

## Output Format

Return all texts in HTML format with clear separators:

<div class="text-1">
<h3>Text 1 Title</h3>
<p>Content of first text...</p>
</div>

<div class="text-2">
<h3>Text 2 Title</h3>
<p>Content of second text...</p>
</div>

Now generate the texts in {$language}:
EOT;
    }

    /**
     * Определяет тип passage на основе группы
     */
    protected function detectPassageType(QuestionGroup $group): string
    {
        $title = strtolower($group->title ?? '');

        // Article keywords
        $articleKeywords = ['article', 'artykuł', 'publicystyczny', 'journalistic', 'newspaper', 'magazine'];
        foreach ($articleKeywords as $keyword) {
            if (str_contains($title, $keyword)) {
                return 'article';
            }
        }

        // Academic keywords
        $academicKeywords = ['academic', 'lecture', 'research', 'naukowy', 'uniwersytet', 'study'];
        foreach ($academicKeywords as $keyword) {
            if (str_contains($title, $keyword)) {
                return 'academic';
            }
        }

        // Report keywords
        $reportKeywords = ['report', 'raport', 'dokument', 'analiza', 'analysis'];
        foreach ($reportKeywords as $keyword) {
            if (str_contains($title, $keyword)) {
                return 'report';
            }
        }

        // Letter keywords
        $letterKeywords = ['letter', 'list', 'email', 'wiadomość'];
        foreach ($letterKeywords as $keyword) {
            if (str_contains($title, $keyword)) {
                return 'letter';
            }
        }

        // Mixed/short texts keywords
        $mixedKeywords = ['short', 'krótkі', 'krótkie', 'announcements', 'ogłoszenia', 'dopasowanie', 'matching'];
        foreach ($mixedKeywords as $keyword) {
            if (str_contains($title, $keyword)) {
                return 'mixed';
            }
        }

        // Default: article (most common)
        return 'article';
    }

    /**
     * Очищает passage от markdown
     */
    protected function cleanPassage(string $passage): string
    {
        // Убираем markdown code blocks
        $passage = preg_replace('/```[\w]*\n?/', '', $passage);

        // Убираем лишние переносы строк (больше 2 подряд)
        $passage = preg_replace('/\n{3,}/', "\n\n", $passage);

        // Убираем пробелы в начале и конце
        $passage = trim($passage);

        return $passage;
    }
}
