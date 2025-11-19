<?php

namespace Tests\Unit\Services;

use App\Services\LanguageApp\ImageSearchService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Тесты для ImageSearchService - поиск изображений через провайдеры
 */
class ImageSearchServiceTest extends TestCase
{
    /**
     * Тест: успешный поиск возвращает результаты
     */
    public function test_search_images_returns_results_when_enabled(): void
    {
        // Arrange
        Config::set('ai.images.enabled', true);
        Config::set('ai.images.provider', 'unsplash');
        Config::set('ai.images.search_count', 10);
        Config::set('ai.images.unsplash.api_key', 'test-api-key');
        Config::set('ai.images.unsplash.base_url', 'https://api.unsplash.com');

        // Mock Unsplash API response
        Http::fake([
            'https://api.unsplash.com/search/photos*' => Http::response([
                'results' => [
                    [
                        'urls' => [
                            'regular' => 'https://images.unsplash.com/photo-123',
                            'thumb' => 'https://images.unsplash.com/photo-123?w=200',
                        ],
                        'description' => 'Mountain landscape',
                        'user' => [
                            'name' => 'John Doe',
                            'links' => ['html' => 'https://unsplash.com/@johndoe'],
                        ],
                        'width' => 1920,
                        'height' => 1080,
                        'links' => ['download' => 'https://unsplash.com/photos/123/download'],
                    ],
                    [
                        'urls' => [
                            'regular' => 'https://images.unsplash.com/photo-456',
                            'thumb' => 'https://images.unsplash.com/photo-456?w=200',
                        ],
                        'alt_description' => 'Ocean sunset',
                        'user' => [
                            'name' => 'Jane Smith',
                            'links' => ['html' => 'https://unsplash.com/@janesmith'],
                        ],
                        'width' => 1920,
                        'height' => 1080,
                        'links' => ['download' => 'https://unsplash.com/photos/456/download'],
                    ],
                ],
            ], 200),
        ]);

        Log::shouldReceive('info')->times(4); // ImageSearchService (2x) + UnsplashProvider (2x)

        $service = new ImageSearchService();

        // Act
        $result = $service->searchImages('nature', 10);

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(2, $result);

        // Verify structure of first result
        $this->assertArrayHasKey('url', $result[0]);
        $this->assertArrayHasKey('thumb_url', $result[0]);
        $this->assertArrayHasKey('description', $result[0]);
        $this->assertArrayHasKey('author', $result[0]);
        $this->assertArrayHasKey('author_url', $result[0]);
        $this->assertArrayHasKey('source', $result[0]);
        $this->assertArrayHasKey('license', $result[0]);

        $this->assertEquals('https://images.unsplash.com/photo-123', $result[0]['url']);
        $this->assertEquals('Mountain landscape', $result[0]['description']);
        $this->assertEquals('John Doe', $result[0]['author']);
        $this->assertEquals('unsplash', $result[0]['source']);

        // Verify HTTP request
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.unsplash.com/search/photos?query=nature&per_page=10&orientation=landscape' &&
                   $request->hasHeader('Authorization', 'Client-ID test-api-key');
        });
    }

    /**
     * Тест: возвращает пустой массив когда images.enabled = false
     */
    public function test_search_images_returns_empty_when_disabled(): void
    {
        // Arrange
        Config::set('ai.images.enabled', false);
        Log::shouldReceive('info')->once()->withArgs(function ($message) {
            return str_contains($message, 'Image search disabled');
        });

        $service = new ImageSearchService();

        // Act
        $result = $service->searchImages('nature', 10);

        // Assert
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Тест: обработка ошибок провайдера
     */
    public function test_search_images_handles_provider_errors(): void
    {
        // Arrange
        Config::set('ai.images.enabled', true);
        Config::set('ai.images.provider', 'unsplash');
        Config::set('ai.images.unsplash.api_key', 'test-api-key');

        // Mock Unsplash API error response (429 Too Many Requests)
        Http::fake([
            'https://api.unsplash.com/search/photos*' => Http::response([
                'errors' => ['Rate limit exceeded'],
            ], 429),
        ]);

        Log::shouldReceive('info')->times(2); // ImageSearchService start + UnsplashProvider start
        Log::shouldReceive('error')->times(3); // UnsplashProvider API error + UnsplashProvider catch + ImageSearchService catch

        $service = new ImageSearchService();

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsplash API error');

        $service->searchImages('nature', 10);
    }

    /**
     * Тест: использует count из конфига если не передан
     */
    public function test_search_images_uses_default_count_from_config(): void
    {
        // Arrange
        Config::set('ai.images.enabled', true);
        Config::set('ai.images.provider', 'unsplash');
        Config::set('ai.images.search_count', 20); // Default count
        Config::set('ai.images.unsplash.api_key', 'test-api-key');

        Http::fake([
            'https://api.unsplash.com/search/photos*' => Http::response([
                'results' => [],
            ], 200),
        ]);

        Log::shouldReceive('info')->times(3); // ImageSearchService start + UnsplashProvider start + ImageSearchService completion (no provider completion when empty)
        Log::shouldReceive('warning')->once(); // UnsplashProvider warning when no results

        $service = new ImageSearchService();

        // Act (not passing count parameter)
        $result = $service->searchImages('test');

        // Assert
        $this->assertIsArray($result);
        $this->assertEmpty($result);

        // Verify HTTP request used count=20 from config
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'per_page=20');
        });
    }
}
