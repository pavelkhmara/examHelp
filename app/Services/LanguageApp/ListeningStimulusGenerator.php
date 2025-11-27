<?php

declare(strict_types=1);

namespace App\Services\LanguageApp;

use App\Models\QuestionGroup;
use Illuminate\Support\Facades\Log;

/**
 * Listening Stimulus Generator
 *
 * Генерирует текстовые скрипты для listening заданий через AI.
 *
 * Типы скриптов:
 * 1. UNIFIED - целостный скрипт (лекция, диалог, интервью)
 * 2. FRAGMENTED - набор коротких фрагментов (объявления, новости)
 *
 * Скрипт должен:
 * - Соответствовать уровню экзамена (A1-C2)
 * - Содержать информацию, необходимую для ответа на вопросы
 * - Быть естественным для устной речи (для TTS)
 */
class ListeningStimulusGenerator
{
    public function __construct(
        private readonly AiProvider $ai
    ) {}

    /**
     * Генерирует скрипт для listening группы
     *
     * @param  QuestionGroup  $group  Группа вопросов
     * @param  string  $stimulusType  'unified'|'fragmented'
     * @return string|null Текст скрипта или null если не удалось сгенерировать
     */
    public function generateScript(QuestionGroup $group, string $stimulusType): ?string
    {
        $exam = $group->exam;
        $section = $group->section;
        $questions = $group->questions()->orderBy('order')->get();

        if ($questions->isEmpty()) {
            Log::warning('[ListeningStimulusGenerator] No questions in group', [
                'group_id' => $group->id,
            ]);

            return null;
        }

        Log::info('[ListeningStimulusGenerator] Generating script', [
            'group_id' => $group->id,
            'title' => $group->title,
            'stimulus_type' => $stimulusType,
            'question_count' => $questions->count(),
        ]);

        // Собираем контекст для промпта
        $context = $this->buildContext($group, $questions, $stimulusType);

        // Генерируем через AI
        $prompt = $this->buildPrompt($context, $stimulusType);

        try {
            $response = $this->ai->generate([
                'messages' => [
                    ['role' => 'system', 'content' => $prompt],
                ],
            ], [
                'model' => 'default', // Используем основную модель
            ]);

            if (! ($response['ok'] ?? false)) {
                Log::error('[ListeningStimulusGenerator] AI generation failed', [
                    'group_id' => $group->id,
                    'error' => $response['error'] ?? 'Unknown error',
                ]);

                return null;
            }

            $script = $response['text'] ?? '';

            // Очищаем от markdown если есть
            $script = $this->cleanScript($script);

            if (empty($script)) {
                Log::warning('[ListeningStimulusGenerator] Empty script generated', [
                    'group_id' => $group->id,
                ]);

                return null;
            }

            Log::info('[ListeningStimulusGenerator] Script generated successfully', [
                'group_id' => $group->id,
                'script_length' => mb_strlen($script),
            ]);

            return $script;

        } catch (\Exception $e) {
            Log::error('[ListeningStimulusGenerator] Exception during generation', [
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
    protected function buildContext(QuestionGroup $group, $questions, string $stimulusType): array
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
                'stimulus_text' => $q->stimulus['text_html'] ?? null,
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

        return [
            'exam_title' => $exam->title ?? 'Unknown Exam',
            'exam_level' => $exam->level ?? 'B1',
            'exam_language' => $exam->language_of_test ?? 'English',
            'section_title' => $section->title ?? 'Listening',
            'group_title' => $group->title ?? 'Listening Task',
            'topic' => strip_tags($stimulus['text_html'] ?? ''),
            'suggested_duration_sec' => $metadata['suggested_time_sec'] ?? 120,
            'questions' => $questionsInfo,
            'question_count' => count($questionsInfo),
            'stimulus_type' => $stimulusType,
        ];
    }

    /**
     * Строит промпт для генерации скрипта
     */
    protected function buildPrompt(array $context, string $stimulusType): string
    {
        $examTitle = $context['exam_title'];
        $level = $context['exam_level'];
        $language = $context['exam_language'];
        $groupTitle = $context['group_title'];
        $topic = $context['topic'];
        $duration = $context['suggested_duration_sec'];
        $questionCount = $context['question_count'];
        $questionsJson = json_encode($context['questions'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Рассчитываем примерное количество слов (150 слов/мин средняя скорость речи)
        $targetWords = (int) ($duration / 60 * 130); // чуть медленнее для ясности
        $targetWords = max(100, min(1500, $targetWords)); // ограничиваем

        if ($stimulusType === 'fragmented') {
            return $this->buildFragmentedPrompt($context, $targetWords);
        }

        return $this->buildUnifiedPrompt($context, $targetWords);
    }

    /**
     * Промпт для unified скрипта (лекция, диалог)
     */
    protected function buildUnifiedPrompt(array $context, int $targetWords): string
    {
        $examTitle = $context['exam_title'];
        $level = $context['exam_level'];
        $language = $context['exam_language'];
        $groupTitle = $context['group_title'];
        $topic = $context['topic'];
        $questionCount = $context['question_count'];
        $questionsJson = json_encode($context['questions'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Определяем тип контента по названию
        $contentType = 'monologue'; // по умолчанию
        $titleLower = strtolower($groupTitle);

        if (str_contains($titleLower, 'dialog') || str_contains($titleLower, 'conversation') ||
            str_contains($titleLower, 'rozmowa') || str_contains($titleLower, 'wywiad') ||
            str_contains($titleLower, 'interview') || str_contains($titleLower, 'діалог')) {
            $contentType = 'dialogue';
        } elseif (str_contains($titleLower, 'lecture') || str_contains($titleLower, 'лекция') ||
                  str_contains($titleLower, 'wykład')) {
            $contentType = 'lecture';
        } elseif (str_contains($titleLower, 'news') || str_contains($titleLower, 'wiadomości') ||
                  str_contains($titleLower, 'новости')) {
            $contentType = 'news';
        }

        $contentTypeInstructions = match ($contentType) {
            'dialogue' => <<<'EOT'
Write a natural dialogue between 2 speakers (A and B).
- Include speaker markers: "A:" and "B:" at the beginning of each turn
- Make the conversation flow naturally with reactions and follow-up questions
- Each speaker should have 4-8 turns
EOT,
            'lecture' => <<<'EOT'
Write an academic lecture or presentation.
- Use clear, educational language appropriate for the level
- Include topic introduction, main points, and brief conclusion
- Use transitional phrases ("First...", "Now let's look at...", "In conclusion...")
EOT,
            'news' => <<<'EOT'
Write a news report or broadcast.
- Use journalistic style with clear, factual language
- Include the main story and relevant details
- Keep the tone professional and informative
EOT,
            default => <<<'EOT'
Write a clear monologue or presentation.
- Use natural spoken language
- Include clear structure with introduction and main points
EOT,
        };

        return <<<EOT
# Task: Generate Listening Script

You are creating a listening script for a language exam.

## Exam Context
- **Exam**: {$examTitle}
- **Level**: {$level} (CEFR)
- **Language**: {$language}
- **Task**: {$groupTitle}
- **Topic**: {$topic}

## Requirements

1. **Length**: Approximately {$targetWords} words (for TTS audio generation)

2. **Content Type**: {$contentType}
{$contentTypeInstructions}

3. **Language Level**: Match {$level} CEFR level
   - Use vocabulary and grammar appropriate for {$level}
   - For A1-A2: Simple sentences, common vocabulary
   - For B1-B2: Moderate complexity, some idioms
   - For C1-C2: Complex structures, academic vocabulary

4. **Must Cover**: The script MUST contain information that allows answering ALL these questions:

```json
{$questionsJson}
```

5. **Natural Speech**:
   - Use natural spoken language (contractions, filler words are OK)
   - Avoid overly written/formal style
   - Include natural pauses (you can use "..." for emphasis)

## Output Format

Return ONLY the script text, ready for text-to-speech conversion.
- No markdown formatting
- No headers or labels (except speaker markers for dialogues)
- No meta-commentary

## Example Structure for Dialogue

A: [greeting/opening]
B: [response]
A: [main topic introduction]
B: [reaction/question]
... [continue naturally]

## Example Structure for Lecture

[Topic introduction]
[First main point]
[Second main point]
[Examples or details]
[Conclusion or summary]

Now generate the script in {$language}:
EOT;
    }

    /**
     * Промпт для fragmented скрипта (объявления, короткие сообщения)
     */
    protected function buildFragmentedPrompt(array $context, int $targetWords): string
    {
        $examTitle = $context['exam_title'];
        $level = $context['exam_level'];
        $language = $context['exam_language'];
        $groupTitle = $context['group_title'];
        $questionCount = $context['question_count'];
        $questionsJson = json_encode($context['questions'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $wordsPerFragment = (int) ($targetWords / $questionCount);
        $wordsPerFragment = max(30, min(150, $wordsPerFragment));

        return <<<EOT
# Task: Generate Listening Fragments

You are creating short listening fragments for a language exam.

## Exam Context
- **Exam**: {$examTitle}
- **Level**: {$level} (CEFR)
- **Language**: {$language}
- **Task**: {$groupTitle}

## Requirements

1. **Structure**: Generate {$questionCount} separate short fragments
   - Each fragment: approximately {$wordsPerFragment} words
   - Fragments are independent (different speakers/situations)

2. **Fragment Types** (vary these):
   - Announcement (airport, train station, store)
   - Short news item
   - Voicemail message
   - Advertisement
   - Weather report
   - Public service announcement

3. **Each fragment must**:
   - Be self-contained
   - Contain information to answer ONE corresponding question
   - Be appropriate for {$level} CEFR level

4. **Questions to cover** (one question per fragment):

```json
{$questionsJson}
```

## Output Format

Return fragments separated by blank lines.
Number each fragment: "1.", "2.", etc.

Example:
1. [First announcement/message - ~{$wordsPerFragment} words]

2. [Second announcement/message - ~{$wordsPerFragment} words]

3. [Third announcement/message - ~{$wordsPerFragment} words]

## Important
- No markdown formatting
- Natural spoken language
- Clear pauses between key information
- Match question order to fragment order

Now generate {$questionCount} fragments in {$language}:
EOT;
    }

    /**
     * Очищает скрипт от markdown и лишнего форматирования
     */
    protected function cleanScript(string $script): string
    {
        // Убираем markdown code blocks
        $script = preg_replace('/```[\w]*\n?/', '', $script);

        // Убираем лишние переносы строк (больше 2 подряд)
        $script = preg_replace('/\n{3,}/', "\n\n", $script);

        // Убираем пробелы в начале и конце
        $script = trim($script);

        return $script;
    }
}
