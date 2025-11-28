<?php

declare(strict_types=1);

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Question Group Audio Processor
 *
 * Обрабатывает QuestionGroup и генерирует аудио для stimulus.
 *
 * Типы групповых заданий:
 * 1. UNIFIED - один общий стимулус для всех вопросов (лекция, диалог)
 * 2. FRAGMENTED - стимулус состоит из фрагментов, каждый вопрос относится к своему фрагменту
 *
 * Примеры:
 * - Unified: Лекция 5 минут → 6 вопросов о всей лекции (TOEFL Listening)
 * - Fragmented: 8 коротких объявлений → 8 вопросов, каждый о своём объявлении
 */
class QuestionGroupAudioProcessor
{
    private const PLACEHOLDER_PATTERNS = [
        'placeholder_',
        'placeholder.',
        'placeholder-',
        'PLACEHOLDER',
    ];

    public function __construct(
        private readonly TtsService $tts,
        private readonly ListeningStimulusGenerator $stimulusGenerator
    ) {}

    /**
     * Обрабатывает все группы экзамена и генерирует аудио
     *
     * @return array{processed: int, audio_generated: int, errors: int, skipped: int}
     */
    public function processExam(Exam $exam): array
    {
        $groups = QuestionGroup::where('exam_id', $exam->id)
            ->whereNotNull('stimulus')
            ->get();

        $stats = [
            'processed' => 0,
            'audio_generated' => 0,
            'errors' => 0,
            'skipped' => 0,
        ];

        foreach ($groups as $group) {
            try {
                $result = $this->processGroup($group);
                $stats['processed']++;

                if ($result === 'generated') {
                    $stats['audio_generated']++;
                } elseif ($result === 'skipped') {
                    $stats['skipped']++;
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error('[QuestionGroupAudioProcessor] Failed to process group', [
                    'group_id' => $group->id,
                    'title' => $group->title,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[QuestionGroupAudioProcessor] Exam processing complete', [
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
        // Проверяем нужно ли генерировать аудио
        if (! $this->shouldGenerateAudio($group)) {
            return 'skipped';
        }

        Log::info('[QuestionGroupAudioProcessor] Processing group', [
            'group_id' => $group->id,
            'title' => $group->title,
            'stimulus_type' => $this->detectStimulusType($group),
        ]);

        // Определяем тип стимулуса
        $stimulusType = $this->detectStimulusType($group);

        // Получаем текст для озвучки
        $textToSpeak = $this->getTextForAudio($group, $stimulusType);

        if (empty($textToSpeak)) {
            Log::warning('[QuestionGroupAudioProcessor] No text to speak for group', [
                'group_id' => $group->id,
                'title' => $group->title,
            ]);

            return 'skipped';
        }

        // Генерируем аудио
        $filename = 'group_'.$group->id.'_'.time();

        // Выбираем голос в зависимости от типа контента
        $voice = $this->selectVoice($group);

        $result = $this->tts->generateAudio($textToSpeak, $filename, $voice);

        if (empty($result['url'])) {
            Log::warning('[QuestionGroupAudioProcessor] Audio generation returned empty result', [
                'group_id' => $group->id,
            ]);

            return 'error';
        }

        // Обновляем stimulus группы
        $this->updateGroupStimulus($group, $result['url'], $result['path'], $textToSpeak);

        Log::info('[QuestionGroupAudioProcessor] Audio generated for group', [
            'group_id' => $group->id,
            'title' => $group->title,
            'audio_url' => $result['url'],
            'text_length' => mb_strlen($textToSpeak),
        ]);

        return 'generated';
    }

    /**
     * Определяет нужно ли генерировать аудио для группы
     */
    protected function shouldGenerateAudio(QuestionGroup $group): bool
    {
        // TTS должен быть включен
        if (! config('ai.tts.enabled', false)) {
            return false;
        }

        $stimulus = $group->stimulus ?? [];
        $audioUrls = $stimulus['audio'] ?? [];

        // Нет audio в stimulus - не listening группа
        if (empty($audioUrls)) {
            return false;
        }

        // Проверяем есть ли плейсхолдеры
        foreach ($audioUrls as $url) {
            if ($this->isPlaceholder($url)) {
                return true;
            }
        }

        // Все URL реальные - пропускаем
        return false;
    }

    /**
     * Проверяет является ли URL плейсхолдером
     */
    protected function isPlaceholder(string $url): bool
    {
        $urlLower = strtolower($url);

        foreach (self::PLACEHOLDER_PATTERNS as $pattern) {
            if (str_contains($urlLower, strtolower($pattern))) {
                return true;
            }
        }

        // Проверяем что это не реальный URL
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            // Не валидный URL и не http/https - скорее всего плейсхолдер
            if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Определяет тип стимулуса
     *
     * @return string 'unified'|'fragmented'|'unknown'
     */
    protected function detectStimulusType(QuestionGroup $group): string
    {
        $questions = $group->questions;
        $questionCount = $questions->count();

        // Загружаем exam для контекста
        $exam = $group->exam;
        $section = $group->section;

        // Анализируем метаданные группы
        $metadata = $group->metadata ?? [];
        $title = strtolower($group->title ?? '');
        $sectionSkill = strtolower($section->skill ?? '');

        // Определяем по названию
        $fragmentedKeywords = ['короткі', 'krótkie', 'короткие', 'short', 'announcements', 'объявления', 'ogłoszenia'];
        $unifiedKeywords = ['lecture', 'лекция', 'wykład', 'dialog', 'діалог', 'conversation', 'rozmowa', 'wywiad', 'interview'];

        foreach ($fragmentedKeywords as $keyword) {
            if (str_contains($title, $keyword)) {
                return 'fragmented';
            }
        }

        foreach ($unifiedKeywords as $keyword) {
            if (str_contains($title, $keyword)) {
                return 'unified';
            }
        }

        // Эвристика по количеству вопросов и их типам
        $questionTypes = $questions->pluck('type')->unique()->toArray();

        // True/False, Yes/No обычно к unified stimulus
        $unifiedTypes = ['listen_true_false', 'listen_yes_no_ng', 'true_false', 'yes_no_ng'];
        $hasUnifiedTypes = ! empty(array_intersect($questionTypes, $unifiedTypes));

        if ($hasUnifiedTypes && $questionCount >= 4) {
            return 'unified';
        }

        // MCQ с большим количеством вопросов (> 5) обычно fragmented
        if (in_array('listen_mcq', $questionTypes) && $questionCount > 5) {
            // Но может быть и unified - смотрим на другие факторы
            $suggestedTime = $metadata['suggested_time_sec'] ?? 0;

            // Длинное время (> 3 мин) = unified
            if ($suggestedTime > 180) {
                return 'unified';
            }

            return 'fragmented';
        }

        // По умолчанию - unified (безопаснее)
        return 'unified';
    }

    /**
     * Получает текст для озвучки
     */
    protected function getTextForAudio(QuestionGroup $group, string $stimulusType): ?string
    {
        $stimulus = $group->stimulus ?? [];

        // Приоритет 1: Уже есть text_html в stimulus - используем его
        if (! empty($stimulus['text_html'])) {
            $text = strip_tags($stimulus['text_html']);

            // Проверяем что это не просто тема/заголовок
            if (mb_strlen($text) > 100) {
                return $text;
            }
        }

        // Приоритет 2: Собираем текст из вопросов (для fragmented)
        if ($stimulusType === 'fragmented') {
            return $this->buildFragmentedText($group);
        }

        // Приоритет 3: Генерируем текст через AI
        return $this->stimulusGenerator->generateScript($group, $stimulusType);
    }

    /**
     * Собирает текст из фрагментов вопросов
     */
    protected function buildFragmentedText(QuestionGroup $group): ?string
    {
        $questions = $group->questions()->orderBy('order')->get();
        $fragments = [];

        foreach ($questions as $index => $question) {
            $questionStimulus = $question->stimulus ?? [];
            $questionText = $questionStimulus['text_html'] ?? '';

            if (! empty($questionText)) {
                $fragments[] = strip_tags($questionText);
            } else {
                // Пробуем получить из instructions
                $instructions = $question->instructions ?? [];
                $instrText = $instructions['full'] ?? $instructions['brief'] ?? '';

                if (! empty($instrText)) {
                    $fragments[] = $instrText;
                }
            }
        }

        if (empty($fragments)) {
            return null;
        }

        // Объединяем с паузами между фрагментами
        return implode("\n\n", $fragments);
    }

    /**
     * Выбирает голос для озвучки
     */
    protected function selectVoice(QuestionGroup $group): string
    {
        $title = strtolower($group->title ?? '');
        $metadata = $group->metadata ?? [];

        // Conversation/Dialog - можно использовать разные голоса
        // Для простоты пока используем один голос из конфига

        // Можно добавить логику выбора голоса по:
        // - Типу контента (лекция vs диалог)
        // - Языку экзамена
        // - Полу персонажа (если указан в metadata)

        return config('ai.tts.voice', 'alloy');
    }

    /**
     * Обновляет stimulus группы с новым аудио
     */
    protected function updateGroupStimulus(
        QuestionGroup $group,
        string $audioUrl,
        string $audioPath,
        string $transcript
    ): void {
        DB::transaction(function () use ($group, $audioUrl, $audioPath, $transcript) {
            $stimulus = $group->stimulus ?? [];

            // Заменяем плейсхолдеры на реальный URL
            $stimulus['audio'] = [$audioUrl];

            // Сохраняем путь к файлу для возможного удаления
            $stimulus['audio_path'] = $audioPath;

            // Сохраняем транскрипт (полезно для accessibility и проверки)
            $stimulus['transcript'] = $transcript;

            // Помечаем что аудио сгенерировано
            $stimulus['audio_generated'] = true;
            $stimulus['audio_generated_at'] = now()->toIso8601String();

            $group->stimulus = $stimulus;
            $group->save();
        });
    }

    /**
     * Регенерирует аудио для группы (принудительно)
     */
    public function regenerateAudio(QuestionGroup $group): string
    {
        // Удаляем старое аудио если есть
        $stimulus = $group->stimulus ?? [];
        $oldPath = $stimulus['audio_path'] ?? null;

        if ($oldPath) {
            $this->tts->deleteAudio($oldPath);
        }

        // Сбрасываем флаг генерации
        $stimulus['audio_generated'] = false;
        unset($stimulus['audio_path']);
        unset($stimulus['transcript']);

        // Ставим плейсхолдер обратно
        $stimulus['audio'] = ['placeholder_regenerate.mp3'];
        $group->stimulus = $stimulus;
        $group->save();

        // Генерируем заново
        return $this->processGroup($group);
    }
}
