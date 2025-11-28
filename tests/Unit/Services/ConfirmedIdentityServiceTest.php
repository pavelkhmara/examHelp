<?php

namespace Tests\Unit\Services;

use App\Models\ConfirmedIdentity;
use App\Models\GenerationTask;
use App\Services\LanguageApp\ConfirmedIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\ExamTestHelper;
use Tests\TestCase;

/**
 * Тесты для ConfirmedIdentityService - управление подтвержденными идентичностями
 *
 * @group broken
 */
class ConfirmedIdentityServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ConfirmedIdentityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ConfirmedIdentityService;
    }

    /**
     * Тест: создание подтвержденной идентичности
     */
    public function test_create_confirmed_identity(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $task = GenerationTask::factory()->create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'completed',
        ]);

        $identityData = [
            'canonical' => [
                'exam_name' => 'IELTS Academic',
                'family' => 'IELTS',
                'provider' => 'IDP',
                'level' => 'B2',
            ],
            'confidence' => 0.98,
        ];

        // Act
        $confirmed = $this->service->createConfirmedIdentity($exam, $identityData, $task);

        // Assert
        $this->assertInstanceOf(ConfirmedIdentity::class, $confirmed);
        $this->assertEquals($exam->id, $confirmed->exam_id);
        $this->assertEquals($task->id, $confirmed->confirmed_by_task_id);
        $this->assertEquals($identityData, $confirmed->identity_data);
        $this->assertTrue($confirmed->is_valid);
        $this->assertNotNull($confirmed->confirmed_at);

        // Проверить source_fields
        $this->assertArrayHasKey('title', $confirmed->source_fields);
        $this->assertArrayHasKey('user_input', $confirmed->source_fields);
        $this->assertArrayHasKey('document_ids_hash', $confirmed->source_fields);

        // Проверить, что запись создана в БД
        $this->assertDatabaseHas('confirmed_identities', [
            'exam_id' => $exam->id,
            'is_valid' => true,
        ]);
    }

    /**
     * Тест: создание нового подтверждения инвалидирует предыдущие
     */
    public function test_create_confirmed_identity_invalidates_previous(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $task1 = GenerationTask::factory()->create(['exam_id' => $exam->id]);
        $task2 = GenerationTask::factory()->create(['exam_id' => $exam->id]);

        $identityData = ['canonical' => ['exam_name' => 'IELTS Academic']];

        // Создать первое подтверждение
        $confirmed1 = $this->service->createConfirmedIdentity($exam, $identityData, $task1);
        $this->assertTrue($confirmed1->is_valid);

        // Act: создать второе подтверждение
        $confirmed2 = $this->service->createConfirmedIdentity($exam, $identityData, $task2);

        // Assert: первое должно быть инвалидировано
        $confirmed1->refresh();
        $this->assertFalse($confirmed1->is_valid, 'Previous ConfirmedIdentity should be invalidated');
        $this->assertTrue($confirmed2->is_valid, 'New ConfirmedIdentity should be valid');
    }

    /**
     * Тест: shouldRerunIdentity() возвращает true когда нет подтверждения
     */
    public function test_should_rerun_identity_when_no_confirmed_identity(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();

        // Act
        $result = $this->service->shouldRerunIdentity($exam);

        // Assert
        $this->assertTrue($result['should_rerun']);
        $this->assertEquals('No confirmed identity exists', $result['reason']);
        $this->assertNull($result['confirmed_identity']);
    }

    /**
     * Тест: shouldRerunIdentity() возвращает false когда поля не изменились
     */
    public function test_should_not_rerun_when_fields_unchanged(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $task = GenerationTask::factory()->create(['exam_id' => $exam->id]);
        $identityData = ['canonical' => ['exam_name' => 'IELTS Academic']];

        // Создать подтверждение
        $this->service->createConfirmedIdentity($exam, $identityData, $task);

        // Act: проверить без изменений полей
        $result = $this->service->shouldRerunIdentity($exam);

        // Assert
        $this->assertFalse($result['should_rerun']);
        $this->assertNull($result['reason']);
        $this->assertInstanceOf(ConfirmedIdentity::class, $result['confirmed_identity']);
    }

    /**
     * Тест: shouldRerunIdentity() возвращает true когда title изменился
     */
    public function test_should_rerun_when_title_changed(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields(['title' => 'Original Title']);
        $task = GenerationTask::factory()->create(['exam_id' => $exam->id]);
        $identityData = ['canonical' => ['exam_name' => 'IELTS Academic']];

        // Создать подтверждение
        $this->service->createConfirmedIdentity($exam, $identityData, $task);

        // Act: изменить title (используем updateQuietly чтобы не вызывать Observer)
        $exam->updateQuietly(['title' => 'Changed Title']);
        $result = $this->service->shouldRerunIdentity($exam);

        // Assert
        $this->assertTrue($result['should_rerun']);
        $this->assertEquals('Identity-affecting fields have changed', $result['reason']);
        $this->assertArrayHasKey('changed_fields', $result);
        $this->assertContains('title', $result['changed_fields']);
    }

    /**
     * Тест: shouldRerunIdentity() возвращает true когда user_input изменился
     */
    public function test_should_rerun_when_user_input_changed(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields(['user_input' => 'Original input']);
        $task = GenerationTask::factory()->create(['exam_id' => $exam->id]);
        $identityData = ['canonical' => ['exam_name' => 'IELTS Academic']];

        $this->service->createConfirmedIdentity($exam, $identityData, $task);

        // Act: изменить user_input (используем updateQuietly чтобы не вызывать Observer)
        $exam->updateQuietly(['user_input' => 'Changed input']);
        $result = $this->service->shouldRerunIdentity($exam);

        // Assert
        $this->assertTrue($result['should_rerun']);
        $this->assertContains('user_input', $result['changed_fields']);
    }

    /**
     * Тест: shouldRerunIdentity() проверяет изменение document_ids_hash
     */
    public function test_should_rerun_when_documents_changed(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $task = GenerationTask::factory()->create(['exam_id' => $exam->id]);
        $identityData = ['canonical' => ['exam_name' => 'IELTS Academic']];

        // Создать подтверждение без документов
        $this->service->createConfirmedIdentity($exam, $identityData, $task);

        // Act: добавить документ (изменит document_ids_hash)
        $exam->documents()->create([
            'path' => 'test.pdf',
            'original_name' => 'test.pdf',
            'mime' => 'application/pdf',
            'size' => 100 * 1024, // размер в байтах
        ]);

        $result = $this->service->shouldRerunIdentity($exam);

        // Assert
        $this->assertTrue($result['should_rerun']);
        $this->assertContains('document_ids_hash', $result['changed_fields']);
    }

    /**
     * Тест: invalidateConfirmedIdentity() инвалидирует подтверждение
     */
    public function test_invalidate_confirmed_identity(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $task = GenerationTask::factory()->create(['exam_id' => $exam->id]);
        $identityData = ['canonical' => ['exam_name' => 'IELTS Academic']];

        $confirmed = $this->service->createConfirmedIdentity($exam, $identityData, $task);
        $this->assertTrue($confirmed->is_valid);

        // Act
        $this->service->invalidateConfirmedIdentity($exam, 'Test reason');

        // Assert
        $confirmed->refresh();
        $this->assertFalse($confirmed->is_valid);

        $this->assertDatabaseHas('confirmed_identities', [
            'id' => $confirmed->id,
            'is_valid' => false,
        ]);
    }

    /**
     * Тест: invalidateConfirmedIdentity() инвалидирует все valid подтверждения
     */
    public function test_invalidate_all_valid_confirmed_identities(): void
    {
        // Arrange: создать 2 valid подтверждения (unlikely scenario, но для теста)
        $exam = ExamTestHelper::createExamWithAllFields();

        $confirmed1 = ConfirmedIdentity::create([
            'exam_id' => $exam->id,
            'identity_data' => ['test' => 1],
            'source_fields' => [],
            'confirmed_at' => now(),
            'is_valid' => true,
        ]);

        $confirmed2 = ConfirmedIdentity::create([
            'exam_id' => $exam->id,
            'identity_data' => ['test' => 2],
            'source_fields' => [],
            'confirmed_at' => now(),
            'is_valid' => true,
        ]);

        // Act
        $this->service->invalidateConfirmedIdentity($exam);

        // Assert
        $confirmed1->refresh();
        $confirmed2->refresh();
        $this->assertFalse($confirmed1->is_valid);
        $this->assertFalse($confirmed2->is_valid);
    }

    /**
     * Тест: compareWithConfirmed() определяет различия
     */
    public function test_compare_with_confirmed_detects_changes(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $task = GenerationTask::factory()->create(['exam_id' => $exam->id]);

        $confirmedData = [
            'canonical' => [
                'exam_name' => 'IELTS Academic',
                'family' => 'IELTS',
                'provider' => 'IDP',
                'level' => 'B2',
            ],
        ];

        $this->service->createConfirmedIdentity($exam, $confirmedData, $task);

        // Act: сравнить с новыми данными
        $newData = [
            'canonical' => [
                'exam_name' => 'IELTS General',  // Changed
                'family' => 'IELTS',
                'provider' => 'British Council',  // Changed
                'level' => 'B2',
            ],
        ];

        $result = $this->service->compareWithConfirmed($exam, $newData);

        // Assert
        $this->assertFalse($result['matches']);
        $this->assertNotEmpty($result['differences']);
        $this->assertCount(2, $result['differences']); // exam_name и provider изменились
    }

    /**
     * Тест: compareWithConfirmed() возвращает matches=true когда данные совпадают
     */
    public function test_compare_with_confirmed_matches_when_same(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $task = GenerationTask::factory()->create(['exam_id' => $exam->id]);

        $identityData = [
            'canonical' => [
                'exam_name' => 'IELTS Academic',
                'family' => 'IELTS',
                'provider' => 'IDP',
                'level' => 'B2',
            ],
        ];

        $this->service->createConfirmedIdentity($exam, $identityData, $task);

        // Act: сравнить с теми же данными
        $result = $this->service->compareWithConfirmed($exam, $identityData);

        // Assert
        $this->assertTrue($result['matches']);
        $this->assertEmpty($result['differences']);
    }

    /**
     * Тест: compareWithConfirmed() возвращает false когда нет подтверждения
     */
    public function test_compare_with_confirmed_returns_false_when_no_confirmed(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $newData = ['canonical' => ['exam_name' => 'IELTS Academic']];

        // Act
        $result = $this->service->compareWithConfirmed($exam, $newData);

        // Assert
        $this->assertFalse($result['matches']);
        $this->assertContains('No confirmed identity', $result['differences']);
    }

    /**
     * Тест: getLastConfirmedIdentity() возвращает последнее подтверждение
     */
    public function test_get_last_confirmed_identity(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $task1 = GenerationTask::factory()->create(['exam_id' => $exam->id]);
        $task2 = GenerationTask::factory()->create(['exam_id' => $exam->id]);

        // Создать 2 подтверждения
        $confirmed1 = $this->service->createConfirmedIdentity(
            $exam,
            ['canonical' => ['exam_name' => 'First']],
            $task1
        );

        sleep(1); // Ensure different timestamps

        $confirmed2 = $this->service->createConfirmedIdentity(
            $exam,
            ['canonical' => ['exam_name' => 'Second']],
            $task2
        );

        // Act
        $last = $this->service->getLastConfirmedIdentity($exam);

        // Assert
        $this->assertNotNull($last);
        $this->assertEquals($confirmed2->id, $last->id);
        $this->assertEquals('Second', $last->identity_data['canonical']['exam_name']);
    }

    /**
     * Тест: getLastConfirmedIdentity() возвращает null когда нет подтверждений
     */
    public function test_get_last_confirmed_identity_returns_null_when_none(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();

        // Act
        $last = $this->service->getLastConfirmedIdentity($exam);

        // Assert
        $this->assertNull($last);
    }

    /**
     * Тест: source_fields содержит все IDENTITY_AFFECTING_FIELDS
     */
    public function test_source_fields_contains_all_affecting_fields(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields([
            'title' => 'Test Title',
            'user_input' => 'Test Input',
            'level' => 'B2',
            'description' => 'Test Description',
        ]);

        $task = GenerationTask::factory()->create(['exam_id' => $exam->id]);
        $identityData = ['canonical' => ['exam_name' => 'Test']];

        // Act
        $confirmed = $this->service->createConfirmedIdentity($exam, $identityData, $task);

        // Assert
        $sourceFields = $confirmed->source_fields;
        foreach (ConfirmedIdentityService::IDENTITY_AFFECTING_FIELDS as $field) {
            $this->assertArrayHasKey($field, $sourceFields, "source_fields should contain '{$field}'");
        }

        $this->assertArrayHasKey('document_ids_hash', $sourceFields);
    }
}
