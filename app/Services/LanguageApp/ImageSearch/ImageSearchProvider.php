<?php

declare(strict_types=1);

namespace App\Services\LanguageApp\ImageSearch;

/**
 * Interface for image search providers
 */
interface ImageSearchProvider
{
    /**
     * Search for images by query
     *
     * @param  string  $query  Search query (e.g., "mountain landscape", "business meeting")
     * @param  int  $count  Number of images to return
     * @return array<array{url: string, thumb_url: string, description: string, author: string, author_url: string, source: string, license: string}>
     *
     * @throws \Exception
     */
    public function search(string $query, int $count = 15): array;

    /**
     * Get provider name
     */
    public function getName(): string;
}
