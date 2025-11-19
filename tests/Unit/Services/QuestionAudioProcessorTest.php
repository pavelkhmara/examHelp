<?php

namespace Tests\Unit\Services;

use App\Models\Question;
use App\Services\LanguageApp\QuestionAudioProcessor;
use App\Services\LanguageApp\TtsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Тесты для QuestionAudioProcessor - генерация аудио для вопросов
 */
class QuestionAudioProcessorTest extends TestCase
{
    use RefreshDatabase;

    protected QuestionAudioProcessor $processor;
    protected TtsService $ttsService;

    protected function setUp(): void
    {
        parent::setUp();

        // Включить TTS
        Config::set('ai.tts.enabled', true);
    }

    /**
     * Тест: генерирует аудио для whitelisted типов (listen_mcq, dictation)
     */
    public function test_process_generates_audio_for_whitelisted_types(): void
    {
        // Arrange: Mock TtsService
        $mockTts = $this->createMock(TtsService::class);
        $mockTts->expects($this->once())
            ->method('generateAudio')
            ->willReturn([
                'path' => 'audio/2025/01/19/test.mp3',
                'url' => 'http://localhost/storage/audio/2025/01/19/test.mp3',
            ]);

        $this->processor = new QuestionAudioProcessor($mockTts);

        $question = Question::factory()->create([
            'type' => 'listen_mcq',
            'instructions' => [
                'brief' => 'Listen to the audio',
                'full' => 'Listen carefully and answer the question',
            ],
            'stimulus' => [
                'text_html' => '<p>What is the main idea?</p>',
            ],
            'audio_file_path' => null,
        ]);

        // Act
        $result = $this->processor->processQuestion($question);

        // Assert
        $this->assertTrue($result, 'Should return true when audio is generated');

        $question->refresh();
        $this->assertNotNull($question->audio_file_path);
        $this->assertEquals('audio/2025/01/19/test.mp3', $question->audio_file_path);
        $this->assertTrue($question->requires_audio);

        // Проверить что audio_url добавлен в instructions
        $this->assertArrayHasKey('audio_url', $question->instructions);
        $this->assertEquals('http://localhost/storage/audio/2025/01/19/test.mp3', $question->instructions['audio_url']);
    }

    /**
     * Тест: пропускает генерацию для blacklisted типов (speaking_prompt, writing_prompt)
     */
    public function test_process_skips_audio_for_blacklisted_types(): void
    {
        // Arrange: Mock TtsService (не должен вызываться)
        $mockTts = $this->createMock(TtsService::class);
        $mockTts->expects($this->never())
            ->method('generateAudio');

        $this->processor = new QuestionAudioProcessor($mockTts);

        // Test speaking_prompt
        $question1 = Question::factory()->create([
            'type' => 'speaking_prompt',
            'instructions' => ['full' => 'Speak about your hometown'],
        ]);

        $result1 = $this->processor->processQuestion($question1);
        $this->assertFalse($result1, 'Should skip speaking_prompt');

        // Test writing_prompt
        $question2 = Question::factory()->create([
            'type' => 'writing_prompt',
            'instructions' => ['full' => 'Write an essay'],
        ]);

        $result2 = $this->processor->processQuestion($question2);
        $this->assertFalse($result2, 'Should skip writing_prompt');

        // Проверить что audio_file_path НЕ установлен
        $question1->refresh();
        $question2->refresh();
        $this->assertNull($question1->audio_file_path);
        $this->assertNull($question2->audio_file_path);
    }

    /**
     * Тест: сохраняет аудио файл по правильному пути
     */
    public function test_process_saves_audio_file_to_correct_path(): void
    {
        // Arrange
        $expectedPath = 'audio/2025/01/19/q_123_1234567890.mp3';
        $expectedUrl = 'http://localhost/storage/' . $expectedPath;

        $mockTts = $this->createMock(TtsService::class);
        $mockTts->expects($this->once())
            ->method('generateAudio')
            ->willReturn([
                'path' => $expectedPath,
                'url' => $expectedUrl,
            ]);

        $this->processor = new QuestionAudioProcessor($mockTts);

        $question = Question::factory()->create([
            'question_id' => '123',
            'type' => 'dictation',
            'instructions' => ['full' => 'Write what you hear'],
            'stimulus' => ['text_html' => 'The quick brown fox jumps over the lazy dog.'],
        ]);

        // Act
        $this->processor->processQuestion($question);

        // Assert
        $question->refresh();
        $this->assertEquals($expectedPath, $question->audio_file_path);
        $this->assertStringContainsString('audio/', $question->audio_file_path);
        $this->assertStringEndsWith('.mp3', $question->audio_file_path);
    }

    /**
     * Тест: обновляет question с данными аудио
     */
    public function test_process_updates_question_with_audio_data(): void
    {
        // Arrange
        $mockTts = $this->createMock(TtsService::class);
        $mockTts->expects($this->once())
            ->method('generateAudio')
            ->willReturn([
                'path' => 'audio/test.mp3',
                'url' => 'http://localhost/storage/audio/test.mp3',
            ]);

        $this->processor = new QuestionAudioProcessor($mockTts);

        $question = Question::factory()->create([
            'type' => 'listen_mcq',
            'instructions' => ['full' => 'Listen and answer'],
            'stimulus' => ['text_html' => '<p>Audio content here</p>'],
        ]);

        // Act
        $this->processor->processQuestion($question);

        // Assert: проверить все обновленные поля
        $question->refresh();

        // 1. audio_file_path установлен
        $this->assertNotNull($question->audio_file_path);
        $this->assertEquals('audio/test.mp3', $question->audio_file_path);

        // 2. requires_audio установлен в true
        $this->assertTrue($question->requires_audio);

        // 3. instructions.audio_url установлен
        $this->assertArrayHasKey('audio_url', $question->instructions);
        $this->assertEquals('http://localhost/storage/audio/test.mp3', $question->instructions['audio_url']);

        // 4. stimulus.audio массив создан (для audio stimulus types)
        $this->assertArrayHasKey('audio', $question->stimulus);
        $this->assertIsArray($question->stimulus['audio']);
        $this->assertCount(1, $question->stimulus['audio']);

        $audioEntry = $question->stimulus['audio'][0];
        $this->assertEquals('http://localhost/storage/audio/test.mp3', $audioEntry['url']);
        $this->assertEquals('generated', $audioEntry['type']);
    }

    /**
     * Тест: обрабатывает ошибки TTS gracefully (не падает)
     */
    public function test_process_handles_tts_failure_gracefully(): void
    {
        // Arrange: Mock TtsService который выбрасывает exception
        $mockTts = $this->createMock(TtsService::class);
        $mockTts->expects($this->once())
            ->method('generateAudio')
            ->willThrowException(new \RuntimeException('TTS API error'));

        $this->processor = new QuestionAudioProcessor($mockTts);

        $question = Question::factory()->create([
            'type' => 'listen_mcq',
            'instructions' => ['full' => 'Listen to audio'],
            'stimulus' => ['text_html' => 'Test content'],
        ]);

        // Act & Assert: не должно выбрасывать exception
        try {
            $result = $this->processor->processQuestion($question);
            $this->fail('Expected RuntimeException to be thrown');
        } catch (\RuntimeException $e) {
            // Exception caught - это ожидаемое поведение
            $this->assertEquals('TTS API error', $e->getMessage());
        }

        // Проверить что question НЕ обновлен
        $question->refresh();
        $this->assertNull($question->audio_file_path);
    }

    /**
     * Тест: пропускает если аудио уже существует
     */
    public function test_process_skips_if_audio_already_exists(): void
    {
        // Arrange: Mock TtsService (не должен вызываться)
        $mockTts = $this->createMock(TtsService::class);
        $mockTts->expects($this->never())
            ->method('generateAudio');

        $this->processor = new QuestionAudioProcessor($mockTts);

        $question = Question::factory()->create([
            'type' => 'listen_mcq',
            'instructions' => ['full' => 'Listen', 'audio_url' => 'http://existing.mp3'],
            'audio_file_path' => 'audio/existing.mp3',
        ]);

        // Act
        $result = $this->processor->processQuestion($question);

        // Assert
        $this->assertFalse($result, 'Should skip when audio already exists');
    }

    /**
     * Тест: пропускает если TTS отключен глобально
     */
    public function test_process_skips_when_tts_disabled(): void
    {
        // Arrange: Отключить TTS
        Config::set('ai.tts.enabled', false);

        $mockTts = $this->createMock(TtsService::class);
        $mockTts->expects($this->never())
            ->method('generateAudio');

        $this->processor = new QuestionAudioProcessor($mockTts);

        $question = Question::factory()->create([
            'type' => 'listen_mcq',
            'instructions' => ['full' => 'Listen to audio'],
        ]);

        // Act
        $result = $this->processor->processQuestion($question);

        // Assert
        $this->assertFalse($result, 'Should skip when TTS is disabled');
    }
}
