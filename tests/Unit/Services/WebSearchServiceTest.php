<?php

namespace Tests\Unit\Services;

use App\Services\LanguageApp\AiProvider;
use App\Services\LanguageApp\WebSearchService;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Тесты для WebSearchService - поиск информации через AI
 */
class WebSearchServiceTest extends TestCase
{
    protected WebSearchService $service;
    protected AiProvider $mockAi;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock AiProvider
        $this->mockAi = $this->createMock(AiProvider::class);
        $this->service = new WebSearchService($this->mockAi);
    }

    /**
     * Тест: успешный поиск возвращает результаты
     */
    public function test_search_returns_successful_results(): void
    {
        // Arrange
        $query = 'IELTS Academic test structure';
        $expectedSummary = 'IELTS Academic consists of 4 sections: Listening, Reading, Writing, Speaking...';

        $this->mockAi->expects($this->once())
            ->method('generate')
            ->willReturn([
                'content' => $expectedSummary,
            ]);

        // Act
        $result = $this->service->search($query);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('query', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('source', $result);

        $this->assertEquals($query, $result['query']);
        $this->assertEquals($expectedSummary, $result['summary']);
        $this->assertEquals('ai_knowledge', $result['source']);
        $this->assertArrayNotHasKey('error', $result);
    }

    /**
     * Тест: обработка ошибок AI
     */
    public function test_search_handles_ai_failures(): void
    {
        // Arrange
        Log::shouldReceive('error')->once();

        $query = 'Test exam structure';

        $this->mockAi->expects($this->once())
            ->method('generate')
            ->willThrowException(new \RuntimeException('AI API error'));

        // Act
        $result = $this->service->search($query);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('query', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('error', $result);

        $this->assertEquals($query, $result['query']);
        $this->assertNull($result['summary']);
        $this->assertStringContainsString('AI API error', $result['error']);
    }

    /**
     * Тест: извлечение JSON content из AI response
     */
    public function test_search_extracts_json_content(): void
    {
        // Arrange
        $query = 'Exam info';
        $jsonContent = [
            'exam' => 'IELTS',
            'sections' => ['Listening', 'Reading', 'Writing', 'Speaking'],
        ];

        // AI returns JSON string
        $this->mockAi->expects($this->once())
            ->method('generate')
            ->willReturn([
                'content' => json_encode($jsonContent),
            ]);

        // Act
        $result = $this->service->search($query);

        // Assert
        $this->assertIsArray($result['summary']);
        $this->assertEquals($jsonContent, $result['summary']);
    }

    /**
     * Тест: поддержка альтернативного формата AI response (OpenAI)
     */
    public function test_search_extracts_from_openai_format(): void
    {
        // Arrange
        $query = 'Test query';
        $expectedContent = 'OpenAI response content';

        // OpenAI format: body.choices[0].message.content
        $this->mockAi->expects($this->once())
            ->method('generate')
            ->willReturn([
                'body' => [
                    'choices' => [
                        [
                            'message' => [
                                'content' => $expectedContent,
                            ],
                        ],
                    ],
                ],
            ]);

        // Act
        $result = $this->service->search($query);

        // Assert
        $this->assertEquals($expectedContent, $result['summary']);
    }
}
