<?php

declare(strict_types=1);

namespace App\Services\LanguageApp;

use App\Services\LanguageApp\ImageSearch\ImageSearchProvider;
use App\Services\LanguageApp\ImageSearch\UnsplashImageSearchProvider;
use Illuminate\Support\Facades\Log;

/**
 * Image Search Service
 *
 * Searches for images using configured provider (Unsplash, Pexels, Pixabay)
 */
class ImageSearchService
{
    private ImageSearchProvider $provider;

    public function __construct()
    {
        $this->provider = $this->createProvider();
    }

    /**
     * Search for images by query
     *
     * @param  string  $query  Search query
     * @param  int|null  $count  Number of images to return (defaults to config)
     * @return array<array{url: string, thumb_url: string, description: string, author: string, author_url: string, source: string, license: string}>
     *
     * @throws \Exception
     */
    public function searchImages(string $query, ?int $count = null): array
    {
        // Check if image search is enabled
        if (! config('ai.images.enabled', false)) {
            Log::info('[ImageSearchService] Image search disabled, skipping');

            return [];
        }

        $count = $count ?? config('ai.images.search_count', 15);

        Log::info('[ImageSearchService] Searching images', [
            'provider' => $this->provider->getName(),
            'query' => $query,
            'count' => $count,
        ]);

        try {
            $results = $this->provider->search($query, $count);

            Log::info('[ImageSearchService] Search completed', [
                'provider' => $this->provider->getName(),
                'query' => $query,
                'found' => count($results),
            ]);

            return $results;

        } catch (\Exception $e) {
            Log::error('[ImageSearchService] Search failed', [
                'provider' => $this->provider->getName(),
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Create provider instance based on config
     */
    private function createProvider(): ImageSearchProvider
    {
        $providerName = config('ai.images.provider', 'unsplash');

        return match ($providerName) {
            'unsplash' => new UnsplashImageSearchProvider,
            // TODO: Add more providers
            // 'pexels' => new PexelsImageSearchProvider(),
            // 'pixabay' => new PixabayImageSearchProvider(),
            default => throw new \RuntimeException("Unknown image provider: {$providerName}"),
        };
    }

    /**
     * Get current provider name
     */
    public function getProviderName(): string
    {
        return $this->provider->getName();
    }
}
