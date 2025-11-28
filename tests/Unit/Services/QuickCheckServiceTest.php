<?php

namespace Tests\Unit\Services;

use App\Models\Exam;
use App\Services\LanguageApp\QuickCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\ExamTestHelper;
use Tests\TestCase;

/**
 * Тесты для QuickCheckService - проверка готовности экзамена к research
 *
 * @group broken
 */
class QuickCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    protected QuickCheckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new QuickCheckService;
    }

    /**
     * Тест: все обязательные поля заполнены → ready = true
     */
    public function test_check_returns_ready_when_all_fields_present(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();

        // Act
        $result = $this->service->check($exam);

        // Assert
        $this->assertTrue($result['ready'], 'Exam should be ready when all fields are present');
        $this->assertEmpty($result['missing_critical'], 'Should have no critical fields missing');
        $this->assertEmpty($result['missing_recommended'], 'Should have no recommended fields missing');
        $this->assertEquals(100, $result['completion_percentage'], 'Completion should be 100%');
        $this->assertEquals(7, $result['completed_fields'], 'All 7 fields should be completed');
        $this->assertEquals(7, $result['total_fields'], 'Total fields should be 7');

        // Verify all fields have 'ok' status
        foreach ($result['checks'] as $field => $check) {
            $this->assertEquals('ok', $check['status'], "Field '{$field}' should have 'ok' status");
        }
    }

    /**
     * Тест: критичные поля отсутствуют → ready = false
     */
    public function test_check_returns_not_ready_when_critical_fields_missing(): void
    {
        // Arrange: создать exam без title и user_input (критичные поля)
        $exam = Exam::factory()->create([
            'title' => '', // Критичное поле - пустая строка считается как отсутствие
            'user_input' => null, // Часть has_document_or_input
            // Нет документов
        ]);

        // Act
        $result = $this->service->check($exam);

        // Assert
        $this->assertFalse($result['ready'], 'Exam should NOT be ready when critical fields are missing');
        $this->assertNotEmpty($result['missing_critical'], 'Should have critical fields missing');

        // Проверить, что title и has_document_or_input в списке missing_critical
        $this->assertContains('title', $result['missing_critical'], 'Title should be in missing_critical');
        $this->assertContains('has_document_or_input', $result['missing_critical'], 'has_document_or_input should be in missing_critical');

        // Completion должно быть < 100%
        $this->assertLessThan(100, $result['completion_percentage']);
    }

    /**
     * Тест: проверка расчета completion_percentage
     */
    public function test_check_calculates_completion_percentage(): void
    {
        // Arrange: создать exam с 4 из 7 полей
        // Filled: title, level, user_input (has_document_or_input), description = 4 fields
        // Missing: language_of_test, exam_family, exam_provider = 3 fields
        $exam = Exam::factory()->create([
            'title' => 'Test Exam',
            'level' => 'B2',
            'user_input' => 'Some user input',
            'description' => 'Test description',
            // Missing fields: language_of_test, exam_family (in identity), exam_provider (in identity)
        ]);

        // Act
        $result = $this->service->check($exam);

        // Assert
        // 4 completed out of 7 = 57% (rounded)
        $expectedPercentage = round((4 / 7) * 100);
        $this->assertEquals($expectedPercentage, $result['completion_percentage']);
        $this->assertEquals(4, $result['completed_fields']);
        $this->assertEquals(7, $result['total_fields']);
    }

    /**
     * Тест: идентификация missing_recommended полей
     */
    public function test_check_identifies_missing_recommended_fields(): void
    {
        // Arrange: создать exam с критичными полями, но без рекомендуемых
        $exam = Exam::factory()->create([
            'title' => 'Test Exam',
            'user_input' => 'User input present',
            // Критичные поля заполнены
            // Но рекомендуемые отсутствуют: language_of_test, level, exam_family, exam_provider, description
        ]);

        // Act
        $result = $this->service->check($exam);

        // Assert
        $this->assertTrue($result['ready'], 'Exam should be ready (critical fields present)');
        $this->assertEmpty($result['missing_critical'], 'No critical fields missing');
        $this->assertNotEmpty($result['missing_recommended'], 'Should have recommended fields missing');

        // Проверить, что в missing_recommended есть рекомендуемые поля
        $recommendedFields = QuickCheckService::RECOMMENDED_FIELDS;
        foreach ($result['missing_recommended'] as $missingField) {
            $this->assertContains($missingField, $recommendedFields, "'{$missingField}' should be in RECOMMENDED_FIELDS");
        }
    }

    /**
     * Тест: getChecklistHtml() генерирует валидный HTML
     */
    public function test_get_checklist_html_generates_valid_html(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $checkResult = $this->service->check($exam);

        // Act
        $html = $this->service->getChecklistHtml($checkResult);

        // Assert
        $this->assertIsString($html);
        $this->assertStringContainsString('<div', $html, 'HTML should contain div tags');
        $this->assertStringContainsString('Completion: 100%', $html, 'HTML should show completion percentage');
        $this->assertStringContainsString('✔', $html, 'HTML should contain checkmark icon');

        // Проверить, что все поля отображаются
        foreach (QuickCheckService::REQUIRED_FIELDS as $field => $label) {
            $this->assertStringContainsString($label, $html, "HTML should contain field label: {$label}");
        }
    }

    /**
     * Тест: getChecklistHtml() отображает критичные поля с красным цветом
     */
    public function test_get_checklist_html_shows_critical_fields_in_red(): void
    {
        // Arrange: exam без критичных полей
        $exam = Exam::factory()->create([
            'title' => '', // Пустая строка считается как отсутствие
            'user_input' => null,
        ]);
        $checkResult = $this->service->check($exam);

        // Act
        $html = $this->service->getChecklistHtml($checkResult);

        // Assert
        $this->assertStringContainsString('[CRITICAL]', $html, 'HTML should show [CRITICAL] badge');
        $this->assertStringContainsString('color: red', $html, 'Critical fields should be red');
        $this->assertStringContainsString('✖', $html, 'HTML should contain X icon for missing fields');
    }

    /**
     * Тест: getMissingFieldsMessage() генерирует читаемое сообщение
     */
    public function test_get_missing_fields_message_returns_readable_text(): void
    {
        // Arrange: exam без критичных и рекомендуемых полей
        $exam = Exam::factory()->create([
            'title' => '', // Пустая строка считается как отсутствие
            'user_input' => null,
        ]);
        $checkResult = $this->service->check($exam);

        // Act
        $message = $this->service->getMissingFieldsMessage($checkResult);

        // Assert
        $this->assertIsString($message);
        $this->assertStringContainsString('Critical fields:', $message);
        $this->assertStringContainsString('Exam Title', $message);
        $this->assertStringContainsString('Document or User Input', $message);
    }

    /**
     * Тест: проверка has_document_or_input с документом
     */
    public function test_check_passes_when_document_exists_but_no_user_input(): void
    {
        // Arrange: exam с документом, но без user_input
        $exam = Exam::factory()->create([
            'title' => 'Test Exam',
            'user_input' => null,
        ]);

        // Создать связанный документ
        $exam->documents()->create([
            'path' => 'test.pdf',
            'original_name' => 'test.pdf',
            'mime' => 'application/pdf',
            'size' => 100 * 1024, // размер в байтах
        ]);

        // Act
        $result = $this->service->check($exam);

        // Assert
        $this->assertTrue($result['ready'], 'Exam should be ready when document exists');
        $this->assertNotContains('has_document_or_input', $result['missing_critical']);
        $this->assertEquals('ok', $result['checks']['has_document_or_input']['status']);
    }

    /**
     * Тест: проверка has_document_or_input с user_input
     */
    public function test_check_passes_when_user_input_exists_but_no_document(): void
    {
        // Arrange: exam с user_input, но без документа
        $exam = Exam::factory()->create([
            'title' => 'Test Exam',
            'user_input' => 'User provided exam description',
        ]);

        // Act
        $result = $this->service->check($exam);

        // Assert
        $this->assertTrue($result['ready'], 'Exam should be ready when user_input exists');
        $this->assertNotContains('has_document_or_input', $result['missing_critical']);
        $this->assertEquals('ok', $result['checks']['has_document_or_input']['status']);
    }

    /**
     * Тест: проверка language_of_test из identity['exam_language']
     */
    public function test_check_finds_language_in_identity_exam_language(): void
    {
        // Arrange: exam с language_of_test в identity['exam_language']
        $exam = Exam::factory()->create([
            'title' => 'Test Exam',
            'user_input' => 'test',
            'language_of_test' => null, // Не в прямом поле
            'identity' => [
                'exam_language' => 'en', // Но есть в identity
            ],
        ]);

        // Act
        $result = $this->service->check($exam);

        // Assert
        $this->assertEquals('ok', $result['checks']['language_of_test']['status'], 'language_of_test should be found in identity');
        $this->assertNotContains('language_of_test', $result['missing_recommended']);
    }

    /**
     * Тест: проверка exam_family из identity['canonical']['family']
     */
    public function test_check_finds_exam_family_in_identity_canonical(): void
    {
        // Arrange: exam с family в identity['canonical']['family']
        $exam = Exam::factory()->create([
            'title' => 'Test Exam',
            'user_input' => 'test',
            'identity' => [
                'canonical' => [
                    'family' => 'IELTS',
                ],
            ],
        ]);

        // Act
        $result = $this->service->check($exam);

        // Assert
        $this->assertEquals('ok', $result['checks']['exam_family']['status'], 'exam_family should be found in identity.canonical');
        $this->assertNotContains('exam_family', $result['missing_recommended']);
    }

    /**
     * Тест: проверка exam_provider из identity['canonical']['provider']
     */
    public function test_check_finds_exam_provider_in_identity_canonical(): void
    {
        // Arrange: exam с provider в identity['canonical']['provider']
        $exam = Exam::factory()->create([
            'title' => 'Test Exam',
            'user_input' => 'test',
            'identity' => [
                'canonical' => [
                    'provider' => 'IDP',
                ],
            ],
        ]);

        // Act
        $result = $this->service->check($exam);

        // Assert
        $this->assertEquals('ok', $result['checks']['exam_provider']['status'], 'exam_provider should be found in identity.canonical');
        $this->assertNotContains('exam_provider', $result['missing_recommended']);
    }
}
