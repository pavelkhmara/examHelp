<?php

namespace Tests\Unit\Services;

use App\Models\Exam;
use App\Models\ExamDocument;
use App\Services\LanguageApp\AiProvider;
use App\Services\LanguageApp\DocumentStructureExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Тесты для DocumentStructureExtractor - извлечение структуры из документов через AI
 */
class DocumentStructureExtractorTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentStructureExtractor $extractor;

    protected AiProvider $mockAi;

    protected function setUp(): void
    {
        parent::setUp();

        // Создать mock AI provider
        $this->mockAi = $this->createMock(AiProvider::class);
        $this->extractor = new DocumentStructureExtractor($this->mockAi);
    }

    /**
     * Тест: extract() успешно извлекает структуру из документа
     */
    public function test_extract_successfully_extracts_structure_from_document(): void
    {
        // Arrange: создать exam и document с extracted_text
        $exam = Exam::factory()->create();
        $document = ExamDocument::create([
            'exam_id' => $exam->id,
            'path' => 'sample.pdf',
            'original_name' => 'IELTS Sample Test.pdf',
            'mime' => 'application/pdf',
            'size' => 500 * 1024,
            'extracted_text' => 'IELTS Academic Test. Reading section: 60 minutes, 40 questions. Writing section: 60 minutes, 2 tasks.',
        ]);

        // Mock AI response
        $aiResponse = [
            'ok' => true,
            'content' => json_encode([
                'identity' => [
                    'exam_name' => 'IELTS Academic',
                    'provider' => 'IDP',
                    'language' => 'English',
                    'level' => 'B2-C1',
                    'confidence' => 0.95,
                ],
                'structure' => [
                    'sections' => [
                        [
                            'name' => 'Reading',
                            'duration_minutes' => 60,
                            'tasks' => [
                                ['type' => 'multiple_choice', 'description' => 'Reading comprehension'],
                            ],
                        ],
                    ],
                ],
            ]),
        ];

        $this->mockAi->expects($this->once())
            ->method('generate')
            ->willReturn($aiResponse);

        // Act
        $result = $this->extractor->extract($document);

        // Assert
        $this->assertNotNull($result);
        $this->assertArrayHasKey('identity', $result);
        $this->assertArrayHasKey('structure', $result);
        $this->assertEquals('IELTS Academic', $result['identity']['exam_name']);
        $this->assertEquals(0.95, $result['identity']['confidence']);
        $this->assertCount(1, $result['structure']['sections']);
        $this->assertEquals('Reading', $result['structure']['sections'][0]['name']);
        $this->assertEquals(60, $result['structure']['sections'][0]['duration_minutes']);
    }

    /**
     * Тест: extract() возвращает null когда extracted_text пустой
     */
    public function test_extract_returns_null_when_no_extracted_text(): void
    {
        // Arrange: создать document без extracted_text
        $exam = Exam::factory()->create();
        $document = ExamDocument::create([
            'exam_id' => $exam->id,
            'path' => 'empty.pdf',
            'original_name' => 'empty.pdf',
            'mime' => 'application/pdf',
            'size' => 100 * 1024,
            'extracted_text' => '', // Пустой текст
        ]);

        // Act
        $result = $this->extractor->extract($document);

        // Assert
        $this->assertNull($result, 'Should return null when no extracted text');
    }

    /**
     * Тест: extract() возвращает null когда AI возвращает невалидный JSON
     */
    public function test_extract_returns_null_when_ai_returns_invalid_json(): void
    {
        // Arrange
        $exam = Exam::factory()->create();
        $document = ExamDocument::create([
            'exam_id' => $exam->id,
            'path' => 'test.pdf',
            'original_name' => 'test.pdf',
            'mime' => 'application/pdf',
            'size' => 200 * 1024,
            'extracted_text' => 'Some exam text',
        ]);

        // Mock AI response с невалидным JSON
        $this->mockAi->expects($this->once())
            ->method('generate')
            ->willReturn([
                'ok' => true,
                'content' => '{ invalid json: }', // Невалидный JSON
            ]);

        // Act
        $result = $this->extractor->extract($document);

        // Assert
        $this->assertNull($result, 'Should return null when AI returns invalid JSON');
    }

    /**
     * Тест: extract() возвращает null когда AI response не содержит content
     */
    public function test_extract_returns_null_when_ai_response_missing_content(): void
    {
        // Arrange
        $exam = Exam::factory()->create();
        $document = ExamDocument::create([
            'exam_id' => $exam->id,
            'path' => 'test.pdf',
            'original_name' => 'test.pdf',
            'mime' => 'application/pdf',
            'size' => 200 * 1024,
            'extracted_text' => 'Some exam text',
        ]);

        // Mock AI response без content
        $this->mockAi->expects($this->once())
            ->method('generate')
            ->willReturn([
                'ok' => true,
                // Нет 'content'
            ]);

        // Act
        $result = $this->extractor->extract($document);

        // Assert
        $this->assertNull($result, 'Should return null when AI response missing content');
    }

    /**
     * Тест: formatAsHints() форматирует структуру в hints для research pipeline
     */
    public function test_format_as_hints_formats_structure_correctly(): void
    {
        // Arrange: структурированные данные из extract()
        $structured = [
            'identity' => [
                'exam_name' => 'IELTS Academic',
                'provider' => 'IDP',
                'level' => 'B2',
                'language' => 'English',
                'confidence' => 0.9,
            ],
            'structure' => [
                'sections' => [
                    [
                        'name' => 'Reading',
                        'duration_minutes' => 60,
                        'tasks' => [
                            ['type' => 'multiple_choice', 'description' => 'Reading comprehension'],
                        ],
                    ],
                ],
                'total_duration_minutes' => 180,
            ],
            'archetypes' => [
                [
                    'task_name' => 'Task 1',
                    'task_type' => 'Essay',
                    'section' => 'Writing',
                    'duration_minutes' => 20,
                    'points_available' => 25,
                    'format' => 'essay',
                ],
            ],
            'scoring' => [
                'total_points' => 100,
                'passing_threshold' => 60,
                'scoring_type' => 'objective',
            ],
            'extraction_notes' => ['Note 1', 'Note 2'],
        ];

        // Act
        $hints = $this->extractor->formatAsHints($structured);

        // Assert
        $this->assertArrayHasKey('exam_identity', $hints);
        $this->assertEquals('document', $hints['exam_identity']['source']);
        $this->assertEquals(0.9, $hints['exam_identity']['confidence']);
        $this->assertEquals('IELTS Academic', $hints['exam_identity']['exam_name']);
        $this->assertEquals('IDP', $hints['exam_identity']['provider']);

        $this->assertArrayHasKey('document_structure', $hints);
        $this->assertEquals('document', $hints['document_structure']['source']);
        $this->assertCount(1, $hints['document_structure']['sections']);
        $this->assertEquals(180, $hints['document_structure']['total_duration']);

        $this->assertArrayHasKey('document_archetypes', $hints);
        $this->assertEquals('document', $hints['document_archetypes']['source']);
        $this->assertCount(1, $hints['document_archetypes']['tasks']);

        $this->assertArrayHasKey('document_scoring', $hints);
        $this->assertEquals('document', $hints['document_scoring']['source']);

        $this->assertArrayHasKey('extraction_notes', $hints);
        $this->assertEquals(['Note 1', 'Note 2'], $hints['extraction_notes']);
    }

    /**
     * Тест: formatAsHints() НЕ включает identity если confidence < 0.8
     */
    public function test_format_as_hints_excludes_low_confidence_identity(): void
    {
        // Arrange: structured data с низким confidence
        $structured = [
            'identity' => [
                'exam_name' => 'Unknown Exam',
                'provider' => null,
                'level' => null,
                'language' => 'English',
                'confidence' => 0.5, // Низкий confidence
            ],
            'structure' => [
                'sections' => [],
            ],
        ];

        // Act
        $hints = $this->extractor->formatAsHints($structured);

        // Assert: exam_identity НЕ должен быть включен
        $this->assertArrayNotHasKey('exam_identity', $hints, 'Low confidence identity should be excluded');
    }

    /**
     * Тест: formatAsHints() включает только доступные секции
     */
    public function test_format_as_hints_includes_only_available_sections(): void
    {
        // Arrange: minimal structured data (только identity)
        $structured = [
            'identity' => [
                'exam_name' => 'Test Exam',
                'provider' => 'Test Provider',
                'level' => 'A1',
                'language' => 'English',
                'confidence' => 0.95,
            ],
            // Нет structure, archetypes, scoring
        ];

        // Act
        $hints = $this->extractor->formatAsHints($structured);

        // Assert: должен быть только exam_identity
        $this->assertArrayHasKey('exam_identity', $hints);
        $this->assertArrayNotHasKey('document_structure', $hints);
        $this->assertArrayNotHasKey('document_archetypes', $hints);
        $this->assertArrayNotHasKey('document_scoring', $hints);
    }
}
