<?php

namespace Tests\Unit\Services;

use App\Models\Exam;
use App\Services\LanguageApp\AiProvider;
use App\Services\LanguageApp\ExamMetadataAnalysisService;
use App\Services\LanguageApp\Validators\JsonSchemaExamMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\ExamTestHelper;
use Tests\TestCase;

/**
 * Тесты для ExamMetadataAnalysisService - анализ метаданных экзамена из user input
 */
class ExamMetadataAnalysisServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ExamMetadataAnalysisService $service;

    protected AiProvider $mockAiProvider;

    protected function setUp(): void
    {
        parent::setUp();

        // Используем mock AI provider из config
        $this->mockAiProvider = app(AiProvider::class);
        $validator = new JsonSchemaExamMetadata;
        $this->service = new ExamMetadataAnalysisService($this->mockAiProvider, $validator);
    }

    /**
     * Тест: analyze() парсит полный JSON из user_input без вызова AI
     */
    public function test_analyze_parses_complete_json_without_ai(): void
    {
        // Arrange: exam с полным JSON в user_input
        $completeJson = json_encode([
            'user_language' => 'ru',
            'exam_language' => 'en',
            'exam_purpose' => 'University admission',
            'exam_name' => 'IELTS Academic',
            'exam_family' => 'IELTS',
            'provider' => 'IDP',
            'target_score' => '7.0',
        ]);

        $exam = ExamTestHelper::createExamWithAllFields([
            'user_input' => $completeJson,
        ]);

        // Act
        $result = $this->service->analyze($exam);

        // Assert
        $this->assertArrayHasKey('user_meta', $result);
        $this->assertArrayHasKey('identity', $result);
        $this->assertArrayHasKey('system_analysis', $result);

        $this->assertEquals('ru', $result['user_meta']['user_language']);
        $this->assertEquals('en', $result['user_meta']['exam_language']);
        $this->assertEquals('University admission', $result['user_meta']['exam_purpose']);

        $this->assertEquals('IELTS', $result['identity']['exam_family']);
        $this->assertEquals('IELTS Academic', $result['identity']['exam_name']);
        $this->assertEquals('IDP', $result['identity']['provider']);

        // Проверить, что AI не вызывался (confidence = 1.0 означает "без AI")
        $this->assertEquals(1.0, $result['system_analysis']['confidence']);
        $this->assertContains('Metadata extracted from provided JSON without AI analysis', $result['system_analysis']['info']);
    }

    /**
     * Тест: analyze() вызывает AI когда user_input - обычный текст
     */
    public function test_analyze_calls_ai_for_text_input(): void
    {
        // Arrange: exam с текстовым user_input
        $exam = ExamTestHelper::createExamWithAllFields([
            'user_input' => 'I need to prepare for IELTS Academic exam for university admission',
        ]);

        // Для mock AI provider результат уже предопределен в MockAiProvider

        // Act
        $result = $this->service->analyze($exam);

        // Assert: проверяем, что получили результат (mock вернет что-то)
        $this->assertArrayHasKey('user_meta', $result);
        $this->assertArrayHasKey('identity', $result);
        $this->assertArrayHasKey('system_analysis', $result);
    }

    /**
     * Тест: analyze() отслеживает добавление новых полей в user_meta
     */
    public function test_analyze_tracks_added_user_meta_fields(): void
    {
        // Arrange: exam с пустым user_meta
        $exam = ExamTestHelper::createExamWithAllFields([
            'user_input' => json_encode([
                'user_language' => 'ru',
                'exam_language' => 'en',
                'exam_purpose' => 'University admission',
                'exam_name' => 'IELTS Academic',
                'exam_family' => 'IELTS',
            ]),
        ]);
        $exam->user_meta = []; // Пустой user_meta
        $exam->save();

        // Act
        $result = $this->service->analyze($exam);

        // Assert: должны быть changes для добавленных полей
        $this->assertArrayHasKey('changes', $result['system_analysis']);

        $addedFields = array_filter($result['system_analysis']['changes'], fn ($change) => $change['type'] === 'added');
        $this->assertNotEmpty($addedFields, 'Should have added fields');
    }

    /**
     * Тест: analyze() отслеживает изменения в user_meta
     */
    public function test_analyze_tracks_changed_user_meta_fields(): void
    {
        // Arrange: exam с существующим user_meta
        $exam = ExamTestHelper::createExamWithAllFields([
            'user_input' => json_encode([
                'user_language' => 'ru',
                'exam_language' => 'en',
                'exam_purpose' => 'Work visa', // Изменено
                'exam_name' => 'IELTS Academic',
                'exam_family' => 'IELTS',
            ]),
        ]);
        $exam->user_meta = [
            'user_language' => 'ru',
            'exam_language' => 'en',
            'exam_purpose' => 'University admission', // Старое значение
        ];
        $exam->save();

        // Act
        $result = $this->service->analyze($exam);

        // Assert: должен быть change для exam_purpose
        $this->assertArrayHasKey('changes', $result['system_analysis']);

        $changedFields = array_filter($result['system_analysis']['changes'], function ($change) {
            return $change['type'] === 'changed' && $change['field'] === 'exam_purpose';
        });

        $this->assertNotEmpty($changedFields, 'Should detect change in exam_purpose');

        $change = array_values($changedFields)[0];
        $this->assertEquals('University admission', $change['old_value']);
        $this->assertEquals('Work visa', $change['new_value']);
    }

    /**
     * Тест: analyze() обрабатывает невалидный JSON в user_input как обычный текст
     */
    public function test_analyze_treats_invalid_json_as_text(): void
    {
        // Arrange: exam с невалидным JSON
        $exam = ExamTestHelper::createExamWithAllFields([
            'user_input' => '{ "invalid": json, "missing": quotes }',
        ]);

        // Act
        $result = $this->service->analyze($exam);

        // Assert: должен вызвать AI (так как JSON невалидный)
        // Mock AI provider вернет какой-то результат
        $this->assertArrayHasKey('user_meta', $result);
        $this->assertArrayHasKey('identity', $result);
    }
}
