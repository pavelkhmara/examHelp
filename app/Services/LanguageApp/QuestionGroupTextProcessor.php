<?php

declare(strict_types=1);

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\QuestionGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Question Group Text Processor
 *
 * Обрабатывает QuestionGroup для reading секций и генерирует text stimulus.
 *
 * Типы reading passages:
 * 1. UNIFIED - один общий текст для всех вопросов (article, lecture, report)
 * 2. MIXED - коллекция коротких текстов (announcements, news items)
 *
 * Примеры:
 * - Unified: Академическая статья 2500 слов → 10 вопросов о статье (TOEFL Reading)
 * - Mixed: 5 коротких объявлений → 5 вопросов, каждый о своём объявлении
 */
class QuestionGroupTextProcessor
{
    private const PLACEHOLDER_PATTERNS = [
        'placeholder text',
        'placeholder_text',
        'PLACEHOLDER',
        'Placeholder describing',
    ];

    private const MIN_TEXT_LENGTH = 500; // Минимальная длина текста для reading passage

    public function __construct(
        private readonly ReadingStimulusGenerator $stimulusGenerator
    ) {}

    /**
     * Обрабатывает все reading группы экзамена и генерирует тексты
     *
     * @return array{processed: int, text_generated: int, errors: int, skipped: int}
     */
    public function processExam(Exam $exam): array
    {
        $groups = QuestionGroup::where('exam_id', $exam->id)
            ->whereNotNull('stimulus')
            ->get();

        $stats = [
            'processed' => 0,
            'text_generated' => 0,
            'errors' => 0,
            'skipped' => 0,
        ];

        foreach ($groups as $group) {
            try {
                $result = $this->processGroup($group);
                $stats['processed']++;

                if ($result === 'generated') {
                    $stats['text_generated']++;
                } elseif ($result === 'skipped') {
                    $stats['skipped']++;
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error('[QuestionGroupTextProcessor] Failed to process group', [
                    'group_id' => $group->id,
                    'title' => $group->title,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[QuestionGroupTextProcessor] Exam processing complete', [
            'exam_id' => $exam->id,
            'stats' => $stats,
        ]);

        return $stats;
    }

    /**
     * Обрабатывает одну группу
     *
     * @return string 'generated'|'skipped'|'error'
     */
    public function processGroup(QuestionGroup $group): string
    {
        // Проверяем нужно ли генерировать текст
        if (! $this->shouldGenerateText($group)) {
            return 'skipped';
        }

        Log::info('[QuestionGroupTextProcessor] Processing group', [
            'group_id' => $group->id,
            'title' => $group->title,
            'passage_type' => $this->detectPassageType($group),
        ]);

        // Определяем тип passage
        $passageType = $this->detectPassageType($group);

        // Генерируем текст через AI
        $generatedText = $this->stimulusGenerator->generatePassage($group, $passageType);

        if (empty($generatedText)) {
            Log::warning('[QuestionGroupTextProcessor] Text generation returned empty result', [
                'group_id' => $group->id,
                'title' => $group->title,
            ]);

            return 'error';
        }

        // Обновляем stimulus группы
        $this->updateGroupStimulus($group, $generatedText);

        Log::info('[QuestionGroupTextProcessor] Text generated for group', [
            'group_id' => $group->id,
            'title' => $group->title,
            'text_length' => mb_strlen(strip_tags($generatedText)),
        ]);

        return 'generated';
    }

    /**
     * Определяет нужно ли генерировать текст для группы
     *
     * Генерируем если:
     * - Текст является плейсхолдером
     * - Текст короче MIN_TEXT_LENGTH (500 chars)
     * - Еще не был сгенерирован (нет флага text_generated)
     */
    protected function shouldGenerateText(QuestionGroup $group): bool
    {
        $stimulus = $group->stimulus ?? [];
        $textHtml = $stimulus['text_html'] ?? '';

        // Нет text_html в stimulus - не reading группа
        if (empty($textHtml)) {
            return false;
        }

        // Проверяем есть ли плейсхолдер
        if ($this->isPlaceholder($textHtml)) {
            return true;
        }

        // Проверяем длину текста
        $textPlain = strip_tags($textHtml);
        $textLength = mb_strlen($textPlain);

        // Текст слишком короткий - генерируем
        if ($textLength < self::MIN_TEXT_LENGTH) {
            Log::info('[QuestionGroupTextProcessor] Text too short, will generate', [
                'group_id' => $group->id,
                'current_length' => $textLength,
                'min_length' => self::MIN_TEXT_LENGTH,
            ]);

            return true;
        }

        // Уже есть флаг text_generated - пропускаем
        if (! empty($stimulus['text_generated'])) {
            return false;
        }

        // Текст достаточной длины и не плейсхолдер - пропускаем
        return false;
    }

    /**
     * Проверяет является ли текст плейсхолдером
     */
    protected function isPlaceholder(string $text): bool
    {
        $textLower = strtolower($text);

        foreach (self::PLACEHOLDER_PATTERNS as $pattern) {
            if (str_contains($textLower, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Определяет тип passage
     *
     * @return string 'article'|'academic'|'report'|'letter'|'mixed'
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
        $mixedKeywords = ['short', 'krótkі', 'krótkie', 'короткие', 'announcements', 'ogłoszenia', 'dopasowanie', 'matching'];
        foreach ($mixedKeywords as $keyword) {
            if (str_contains($title, $keyword)) {
                return 'mixed';
            }
        }

        // Default: article (most common for reading comprehension)
        return 'article';
    }

    /**
     * Обновляет stimulus группы с новым текстом
     */
    protected function updateGroupStimulus(
        QuestionGroup $group,
        string $generatedText
    ): void {
        DB::transaction(function () use ($group, $generatedText) {
            $stimulus = $group->stimulus ?? [];

            // Заменяем плейсхолдер/короткий текст на сгенерированный
            $stimulus['text_html'] = $generatedText;

            // Помечаем что текст сгенерирован
            $stimulus['text_generated'] = true;
            $stimulus['text_generated_at'] = now()->toIso8601String();

            // Сохраняем длину для метрик
            $stimulus['text_length'] = mb_strlen(strip_tags($generatedText));

            $group->stimulus = $stimulus;
            $group->save();
        });
    }

    /**
     * Регенерирует текст для группы (принудительно)
     */
    public function regenerateText(QuestionGroup $group): string
    {
        // Сбрасываем флаги генерации
        $stimulus = $group->stimulus ?? [];
        $stimulus['text_generated'] = false;
        unset($stimulus['text_generated_at']);
        unset($stimulus['text_length']);

        // Ставим плейсхолдер обратно
        $stimulus['text_html'] = '<p>Placeholder text for regeneration...</p>';
        $group->stimulus = $stimulus;
        $group->save();

        // Генерируем заново
        return $this->processGroup($group);
    }

    /**
     * Проверяет нужна ли обработка для конкретной группы (публичный метод)
     *
     * Используется для предварительной проверки перед вызовом processGroup()
     */
    public function needsProcessing(QuestionGroup $group): bool
    {
        return $this->shouldGenerateText($group);
    }

    /**
     * Возвращает статистику по группам экзамена
     *
     * @return array{total: int, needs_generation: int, already_generated: int, no_text_stimulus: int}
     */
    public function getExamStats(Exam $exam): array
    {
        $groups = QuestionGroup::where('exam_id', $exam->id)
            ->whereNotNull('stimulus')
            ->get();

        $stats = [
            'total' => $groups->count(),
            'needs_generation' => 0,
            'already_generated' => 0,
            'no_text_stimulus' => 0,
        ];

        foreach ($groups as $group) {
            $stimulus = $group->stimulus ?? [];
            $textHtml = $stimulus['text_html'] ?? '';

            if (empty($textHtml)) {
                $stats['no_text_stimulus']++;

                continue;
            }

            if (! empty($stimulus['text_generated'])) {
                $stats['already_generated']++;

                continue;
            }

            if ($this->shouldGenerateText($group)) {
                $stats['needs_generation']++;
            }
        }

        return $stats;
    }

    /**
     * Консолидирует фрагментированный текст из вопросов в единый passage
     *
     * Используется для TOEFL Reading где текст раздроблен по вопросам
     */
    public function consolidateFragmentedText(QuestionGroup $group): ?string
    {
        $questions = $group->questions()->orderBy('order')->get();

        if ($questions->isEmpty()) {
            return null;
        }

        $fragments = [];

        foreach ($questions as $question) {
            $questionStimulus = $question->stimulus ?? [];
            $questionText = $questionStimulus['text_html'] ?? '';

            if (! empty($questionText)) {
                // Убираем HTML теги и сохраняем чистый текст
                $cleanText = strip_tags($questionText);
                if (mb_strlen($cleanText) > 50) { // Игнорируем короткие фрагменты
                    $fragments[] = $cleanText;
                }
            }
        }

        if (empty($fragments)) {
            return null;
        }

        // Объединяем фрагменты в единый текст
        $consolidatedText = implode("\n\n", $fragments);

        // Оборачиваем в HTML параграфы
        $paragraphs = explode("\n\n", $consolidatedText);
        $html = '';
        foreach ($paragraphs as $para) {
            if (! empty(trim($para))) {
                $html .= '<p>'.htmlspecialchars(trim($para), ENT_QUOTES, 'UTF-8').'</p>'."\n";
            }
        }

        return $html;
    }
}
