<?php

declare(strict_types=1);

namespace App\Services\LanguageApp\ImageSearch;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Unsplash Image Search Provider
 *
 * Uses Unsplash API to search for free high-quality images
 * Docs: https://unsplash.com/documentation#search-photos
 */
class UnsplashImageSearchProvider implements ImageSearchProvider
{
    private ?string $apiKey;

    private string $baseUrl;

    private int $timeout;

    public function __construct()
    {
        $this->apiKey = config('ai.images.unsplash.api_key');
        $this->baseUrl = config('ai.images.unsplash.base_url', 'https://api.unsplash.com');
        $this->timeout = config('ai.images.timeout', 30);

        // Fail-fast validation: если image search enabled но API key отсутствует
        if (config('ai.images.enabled', false) && ! $this->apiKey) {
            throw new \RuntimeException(
                'Image search is enabled (AI_IMAGES_ENABLED=true) but Unsplash API key not configured. '.
                'Set AI_IMAGE_UNSPLASH_KEY in .env file, or disable image search with AI_IMAGES_ENABLED=false.'
            );
        }
    }

    public function getName(): string
    {
        return 'unsplash';
    }

    /**
     * Search for images on Unsplash
     *
     * @param  string  $query  Search query
     * @param  int  $count  Number of results (max 30 per page)
     *
     * @throws \Exception
     */
    public function search(string $query, int $count = 15): array
    {
        if (empty($query)) {
            throw new \InvalidArgumentException('Search query cannot be empty');
        }

        if (! $this->apiKey) {
            Log::warning('[UnsplashImageSearch] API key not configured, returning empty results');

            return [];
        }

        Log::info('[UnsplashImageSearch] Searching images', [
            'query' => $query,
            'count' => $count,
        ]);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Client-ID '.$this->apiKey,
                'Accept-Version' => 'v1',
            ])
                ->timeout($this->timeout)
                ->get($this->baseUrl.'/search/photos', [
                    'query' => $query,
                    'per_page' => min($count, 30), // Unsplash max is 30 per page
                    'orientation' => 'landscape', // Prefer landscape for exam materials
                ]);

            if (! $response->successful()) {
                $error = $response->json('errors.0') ?? $response->body();
                Log::error('[UnsplashImageSearch] API error', [
                    'status' => $response->status(),
                    'error' => $error,
                ]);
                throw new \RuntimeException("Unsplash API error: {$error}");
            }

            $data = $response->json();
            $results = $data['results'] ?? [];

            if (empty($results)) {
                Log::warning('[UnsplashImageSearch] No results found', ['query' => $query]);

                return [];
            }

            $images = [];
            foreach ($results as $result) {
                $images[] = [
                    'url' => $result['urls']['regular'] ?? $result['urls']['full'],
                    'thumb_url' => $result['urls']['thumb'] ?? $result['urls']['small'],
                    'description' => $result['description'] ?? $result['alt_description'] ?? '',
                    'author' => $result['user']['name'] ?? 'Unknown',
                    'author_url' => $result['user']['links']['html'] ?? '',
                    'source' => 'unsplash',
                    'license' => 'Unsplash License (free to use)',
                    'width' => $result['width'] ?? null,
                    'height' => $result['height'] ?? null,
                    'download_url' => $result['links']['download'] ?? $result['urls']['full'],
                ];
            }

            Log::info('[UnsplashImageSearch] Found images', [
                'query' => $query,
                'count' => count($images),
            ]);

            return $images;

        } catch (\Exception $e) {
            Log::error('[UnsplashImageSearch] Failed to search images', [
                'query' => $query,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
