<?php

declare(strict_types=1);

namespace App\Services\LanguageApp;

use App\Models\Question;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Question Audio Processor
 *
 * Обрабатывает вопросы и генерирует аудио для текстовых полей
 * когда это необходимо по структуре экзамена
 */
class QuestionAudioProcessor
{
    public function __construct(
        private readonly TtsService $tts
    ) {}

    /**
     * Обрабатывает вопросы и генерирует аудио где необходимо
     *
     * @param  array<int, Question>  $questions
     * @return array{processed: int, audio_generated: int, errors: int}
     */
    public function processQuestions(array $questions): array
    {
        $processed = 0;
        $audioGenerated = 0;
        $errors = 0;

        foreach ($questions as $question) {
            try {
                $generated = $this->processQuestion($question);
                $processed++;
                if ($generated) {
                    $audioGenerated++;
                }
            } catch (\Exception $e) {
                $errors++;
                Log::error('[QuestionAudioProcessor] Failed to process question', [
                    'question_id' => $question->question_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[QuestionAudioProcessor] Batch processing complete', [
            'processed' => $processed,
            'audio_generated' => $audioGenerated,
            'errors' => $errors,
        ]);

        return [
            'processed' => $processed,
            'audio_generated' => $audioGenerated,
            'errors' => $errors,
        ];
    }

    /**
     * Обрабатывает один вопрос и генерирует аудио если необходимо
     *
     * @return bool True if audio was generated
     */
    public function processQuestion(Question $question): bool
    {
        // Проверяем нужно ли генерировать аудио для этого вопроса
        if (! $this->shouldGenerateAudio($question)) {
            return false;
        }

        // Определяем какой текст озвучивать
        $textToSpeak = $this->getTextToSpeak($question);
        if (empty($textToSpeak)) {
            Log::debug('[QuestionAudioProcessor] No text to speak', [
                'question_id' => $question->question_id,
            ]);

            return false;
        }

        // Генерируем аудио
        $filename = 'q_'.$question->question_id.'_'.time();
        $result = $this->tts->generateAudio($textToSpeak, $filename);

        if (empty($result['path']) || empty($result['url'])) {
            Log::warning('[QuestionAudioProcessor] Audio generation returned empty result', [
                'question_id' => $question->question_id,
            ]);

            return false;
        }

        // Обновляем вопрос
        DB::transaction(function () use ($question, $result) {
            // Сохраняем путь к файлу
            $question->audio_file_path = $result['path'];
            $question->requires_audio = true;

            // Обновляем instructions.audio_url
            $instructions = $question->instructions ?? [];
            $instructions['audio_url'] = $result['url'];
            $question->instructions = $instructions;

            // Также можно добавить в stimulus.audio если это стимул
            if ($this->isAudioStimulus($question)) {
                $stimulus = $question->stimulus ?? [];
                $audio = $stimulus['audio'] ?? [];

                // Добавляем аудио в массив
                $audio[] = [
                    'url' => $result['url'],
                    'type' => 'generated',
                    'duration_sec' => null, // TODO: можно добавить определение длительности
                ];

                $stimulus['audio'] = $audio;
                $question->stimulus = $stimulus;
            }

            $question->save();
        });

        Log::info('[QuestionAudioProcessor] Audio generated for question', [
            'question_id' => $question->question_id,
            'audio_path' => $result['path'],
            'audio_url' => $result['url'],
        ]);

        return true;
    }

    /**
     * Определяет нужно ли генерировать аудио для вопроса
     */
    protected function shouldGenerateAudio(Question $question): bool
    {
        // Если TTS отключен глобально
        if (! config('ai.tts.enabled', false)) {
            return false;
        }

        // Если уже есть аудио файл
        if (! empty($question->audio_file_path)) {
            return false;
        }

        // Если уже есть audio_url в instructions
        $instructions = $question->instructions ?? [];
        if (! empty($instructions['audio_url'])) {
            return false;
        }

        // BLACKLIST: типы где НЕ нужно генерировать аудио (диалоги, пользователь говорит/пишет)
        $noAudioTypes = [
            'speaking_prompt',  // Пользователь говорит (монолог/диалог)
            'roleplay',         // Диалог - пользователь говорит
            'writing_prompt',   // Пользователь пишет
            'translation',      // Перевод текста
        ];

        if (in_array($question->type, $noAudioTypes, true)) {
            return false;
        }

        // ✅ SMART DETECTION: Все типы начинающиеся с 'listen_' требуют аудио (2025-11-26)
        // Автоматически поддерживает: listen_mcq, listen_true_false, listen_yes_no_ng и любые новые listen_* типы
        if (str_starts_with($question->type, 'listen_')) {
            return true;
        }

        // WHITELIST: другие типы где НУЖНО генерировать аудио (пользователь слушает)
        $audioTypes = [
            'dictation',        // Диктант - пользователь слушает и пишет
            'note_completion',  // Заполнение заметок (может быть по аудио)
        ];

        if (in_array($question->type, $audioTypes, true)) {
            return true;
        }

        // Проверяем metadata - если есть тег 'audio' или skill 'listening'
        // НО только если тип не в blacklist
        $metadata = $question->metadata ?? [];
        $tags = $metadata['tags'] ?? [];
        if (in_array('audio', $tags, true)) {
            return true;
        }

        $skills = $question->skills_measured ?? [];
        if (in_array('listening', $skills, true)) {
            return true;
        }

        return false;
    }

    /**
     * Извлекает текст который нужно озвучить
     */
    protected function getTextToSpeak(Question $question): ?string
    {
        // Приоритет 1: stimulus.text_html (основной контент для озвучки)
        $stimulus = $question->stimulus ?? [];
        if (! empty($stimulus['text_html'])) {
            // Убираем HTML теги
            return strip_tags($stimulus['text_html']);
        }

        // Приоритет 2: instructions.full (инструкции)
        $instructions = $question->instructions ?? [];
        if (! empty($instructions['full'])) {
            return $instructions['full'];
        }

        // Приоритет 3: instructions.brief
        if (! empty($instructions['brief'])) {
            return $instructions['brief'];
        }

        return null;
    }

    /**
     * Определяет является ли вопрос аудио-стимулом
     */
    protected function isAudioStimulus(Question $question): bool
    {
        return in_array($question->type, ['listen_mcq', 'dictation'], true);
    }
}
