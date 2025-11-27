<?php

declare(strict_types=1);

namespace App\Services\LanguageApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Text-to-Speech Service
 *
 * Генерирует аудио файлы из текста используя OpenAI TTS API
 * Модель: gpt-4o-mini-tts (или tts-1 для стандартного качества)
 */
class TtsService
{
    private string $apiKey;

    private string $baseUrl;

    private string $model;

    private string $voice;

    private float $speed;

    private int $timeout;

    public function __construct()
    {
        $this->apiKey = config('ai.tts.api_key', config('ai.api_key'));
        $this->baseUrl = config('ai.tts.base_url', 'https://api.openai.com/v1');
        $this->model = config('ai.tts.model', 'tts-1');
        $this->voice = config('ai.tts.voice', 'alloy');
        $this->speed = config('ai.tts.speed', 1.0);
        $this->timeout = config('ai.tts.timeout', 60);
    }

    /**
     * Генерирует аудио файл из текста
     *
     * @param  string  $text  Текст для озвучки
     * @param  string|null  $filename  Имя файла (без расширения), если null - генерируется автоматически
     * @param  string|null  $voice  Голос (alloy, echo, fable, onyx, nova, shimmer)
     * @return array{path: string, url: string} Путь к файлу и публичный URL (пустые строки если TTS отключен)
     *
     * @throws \Exception
     */
    public function generateAudio(string $text, ?string $filename = null, ?string $voice = null): array
    {
        if (empty($text)) {
            throw new \InvalidArgumentException('Text cannot be empty');
        }

        // Проверяем включен ли TTS
        if (! config('ai.tts.enabled', false)) {
            Log::info('[TtsService] TTS disabled, skipping audio generation');

            return [
                'path' => '',
                'url' => '',
            ];
        }

        // Генерируем имя файла если не указано
        if (! $filename) {
            $filename = 'tts_'.md5($text).'_'.time();
        }

        $voice = $voice ?? $this->voice;

        Log::info('[TtsService] Generating audio', [
            'model' => $this->model,
            'voice' => $voice,
            'text_length' => mb_strlen($text),
            'filename' => $filename,
        ]);

        try {
            // Вызываем OpenAI TTS API
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->post($this->baseUrl.'/audio/speech', [
                    'model' => $this->model,
                    'input' => $text,
                    'voice' => $voice,
                    'speed' => $this->speed,
                    'response_format' => 'mp3',
                ]);

            if (! $response->successful()) {
                $error = $response->json('error.message') ?? $response->body();
                Log::error('[TtsService] OpenAI TTS API error', [
                    'status' => $response->status(),
                    'error' => $error,
                ]);
                throw new \RuntimeException("TTS API error: {$error}");
            }

            // Сохраняем аудио файл
            $audioContent = $response->body();
            $relativePath = 'audio/'.date('Y/m/d').'/'.$filename.'.mp3';

            Storage::disk('public')->put($relativePath, $audioContent);

            $fullPath = Storage::disk('public')->path($relativePath);
            $url = Storage::disk('public')->url($relativePath);

            Log::info('[TtsService] Audio generated successfully', [
                'path' => $relativePath,
                'url' => $url,
                'size_bytes' => strlen($audioContent),
            ]);

            return [
                'path' => $relativePath,
                'url' => $url,
            ];

        } catch (\Exception $e) {
            Log::error('[TtsService] Failed to generate audio', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Генерирует аудио для массива текстов (батч)
     *
     * @param  array<int, array{text: string, filename?: string, voice?: string}>  $items
     * @return array<array{path: string|null, url: string|null, error?: string}>
     */
    public function generateBatch(array $items): array
    {
        $results = [];

        foreach ($items as $index => $item) {
            try {
                $text = $item['text'];
                $filename = $item['filename'] ?? null;
                $voice = $item['voice'] ?? null;

                $result = $this->generateAudio($text, $filename, $voice);
                $results[] = $result;

            } catch (\Exception $e) {
                Log::warning('[TtsService] Failed to generate audio for item', [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);

                $results[] = [
                    'path' => null,
                    'url' => null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Удаляет аудио файл
     */
    public function deleteAudio(string $path): bool
    {
        if (! $path) {
            return false;
        }

        try {
            return Storage::disk('public')->delete($path);
        } catch (\Exception $e) {
            Log::error('[TtsService] Failed to delete audio', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Проверяет существует ли аудио файл
     */
    public function audioExists(string $path): bool
    {
        if (! $path) {
            return false;
        }

        return Storage::disk('public')->exists($path);
    }

    /**
     * Получает URL для аудио файла
     */
    public function getAudioUrl(string $path): ?string
    {
        if (! $path || ! $this->audioExists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
