<?php

namespace Tests\Unit\Services;

use App\Services\LanguageApp\TtsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Тесты для TtsService - Text-to-Speech генерация аудио
 */
class TtsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TtsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Настроить конфигурацию TTS
        Config::set('ai.tts.enabled', true);
        Config::set('ai.tts.api_key', 'test-api-key');
        Config::set('ai.tts.model', 'tts-1');
        Config::set('ai.tts.voice', 'alloy');
        Config::set('ai.tts.speed', 1.0);
        Config::set('ai.tts.timeout', 60);

        // Создать fake storage для тестов
        Storage::fake('public');

        // НЕ настраиваем Http::fake() здесь - каждый тест сделает это сам
    }

    /**
     * Тест: успешная генерация аудио
     */
    public function test_generate_audio_success(): void
    {
        // Arrange
        Http::fake([
            'https://api.openai.com/v1/audio/speech' => Http::response('binary audio content', 200),
        ]);

        $this->service = app(TtsService::class);

        // Act
        $result = $this->service->generateAudio('Hello world', 'test-audio');

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('path', $result);
        $this->assertArrayHasKey('url', $result);

        $this->assertNotNull($result['path']);
        $this->assertStringContainsString('audio/', $result['path']);
        $this->assertStringContainsString('test-audio.mp3', $result['path']);

        // Проверить что файл существует в storage
        Storage::disk('public')->assertExists($result['path']);

        // Проверить что HTTP запрос был отправлен
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.openai.com/v1/audio/speech' &&
                   $request['model'] === 'tts-1' &&
                   $request['input'] === 'Hello world' &&
                   $request['voice'] === 'alloy' &&
                   $request['speed'] === 1.0 &&
                   $request['response_format'] === 'mp3';
        });
    }

    /**
     * Тест: выбрасывает exception при пустом тексте
     */
    public function test_generate_audio_throws_exception_on_empty_text(): void
    {
        // Arrange
        $this->service = app(TtsService::class);

        // Expect exception
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Text cannot be empty');

        // Act
        $this->service->generateAudio('');
    }

    /**
     * Тест: обработка ошибки API
     */
    public function test_generate_audio_handles_api_error(): void
    {
        // Arrange: Mock HTTP с ошибкой
        Http::fake([
            'https://api.openai.com/v1/audio/speech' => Http::response([
                'error' => [
                    'message' => 'Invalid API key',
                    'type' => 'invalid_request_error',
                ]
            ], 401),
        ]);

        $this->service = app(TtsService::class);

        // Expect exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TTS API error: Invalid API key');

        // Act
        $this->service->generateAudio('Test text');
    }

    /**
     * Тест: проверка отключения TTS через конфиг
     */
    public function test_generate_audio_respects_tts_disabled_config(): void
    {
        // Arrange: Отключить TTS
        Config::set('ai.tts.enabled', false);

        // Recreate service чтобы конфиг применился
        $this->service = app(TtsService::class);

        // Act
        $result = $this->service->generateAudio('Test text');

        // Assert: должен вернуть null вместо генерации
        $this->assertIsArray($result);
        $this->assertArrayHasKey('path', $result);
        $this->assertArrayHasKey('url', $result);
        $this->assertNull($result['path']);
        $this->assertNull($result['url']);

        // Проверить что HTTP запрос НЕ был отправлен
        Http::assertNothingSent();
    }

    /**
     * Тест: проверка использования правильных параметров запроса
     */
    public function test_generate_audio_uses_correct_parameters(): void
    {
        // Arrange: Mock HTTP
        Http::fake([
            'https://api.openai.com/v1/audio/speech' => Http::response('binary audio content', 200),
        ]);

        // Arrange: Настроить кастомные параметры
        Config::set('ai.tts.model', 'tts-1-hd');
        Config::set('ai.tts.voice', 'nova');
        Config::set('ai.tts.speed', 1.5);

        $this->service = app(TtsService::class);

        // Act
        $result = $this->service->generateAudio('Test with custom params', 'custom-audio', 'echo');

        // Assert
        $this->assertIsArray($result);
        $this->assertNotNull($result['path']);

        // Проверить параметры HTTP запроса
        Http::assertSent(function ($request) {
            return $request['model'] === 'tts-1-hd' &&
                   $request['voice'] === 'echo' &&  // Переопределен в методе
                   $request['speed'] === 1.5 &&
                   $request['input'] === 'Test with custom params' &&
                   $request['response_format'] === 'mp3' &&
                   $request->hasHeader('Authorization', 'Bearer test-api-key') &&
                   $request->hasHeader('Content-Type', 'application/json');
        });
    }

    /**
     * Тест: проверка создания файла в правильной директории
     */
    public function test_generate_audio_saves_file_to_correct_path(): void
    {
        // Arrange
        Http::fake([
            'https://api.openai.com/v1/audio/speech' => Http::response('binary audio content', 200),
        ]);

        $this->service = app(TtsService::class);

        // Act
        $result = $this->service->generateAudio('Test text', 'my-file');

        // Assert: проверить формат пути (audio/YYYY/MM/DD/filename.mp3)
        $this->assertMatchesRegularExpression(
            '/^audio\/\d{4}\/\d{2}\/\d{2}\/my-file\.mp3$/',
            $result['path']
        );

        // Проверить что файл существует
        Storage::disk('public')->assertExists($result['path']);

        // Проверить содержимое файла
        $content = Storage::disk('public')->get($result['path']);
        $this->assertEquals('binary audio content', $content);
    }
}
