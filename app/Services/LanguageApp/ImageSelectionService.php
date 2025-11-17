<?php

declare(strict_types=1);

namespace App\Services\LanguageApp;

use App\Services\LanguageApp\Providers\AiProvider;
use Illuminate\Support\Facades\Log;

/**
 * Image Selection Service
 *
 * Uses GPT-5-mini to evaluate and rank images based on their relevance
 * to the provided text description
 */
class ImageSelectionService
{
    private $aiProvider;

    public function __construct()
    {
        $this->aiProvider = AiProviderFactory::make(
            config('ai.provider'),
            config('ai')
        );
    }

    /**
     * Select best images from search results
     *
     * @param string $description Text description to match against
     * @param array $images Array of images from ImageSearchService
     * @param bool|null $testMode Return top-3 instead of top-1 (defaults to config)
     * @return array{winner: array, alternatives: array, rankings: array}
     * @throws \Exception
     */
    public function selectImages(string $description, array $images, ?bool $testMode = null): array
    {
        if (empty($images)) {
            throw new \InvalidArgumentException('Images array cannot be empty');
        }

        if (empty($description)) {
            throw new \InvalidArgumentException('Description cannot be empty');
        }

        $testMode = $testMode ?? config('ai.images.test_mode', true);
        $topN = $testMode ? 3 : 1;

        Log::info('[ImageSelectionService] Evaluating images', [
            'description_length' => mb_strlen($description),
            'image_count' => count($images),
            'test_mode' => $testMode,
            'top_n' => $topN,
        ]);

        try {
            // Prepare prompt for AI
            $prompt = $this->buildPrompt($description, $images, $topN);

            // Call AI
            $model = config('ai.images.selection_model', 'gpt-5-mini');

            $response = $this->aiProvider->generate(
                [
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are an expert image curator for educational materials. Your task is to evaluate images based on their relevance, quality, and suitability for exam preparation materials.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                ],
                [
                    'model' => $model,
                    'json_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'rankings' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'index' => ['type' => 'integer'],
                                        'score' => ['type' => 'number'],
                                        'reason' => ['type' => 'string'],
                                    ],
                                    'required' => ['index', 'score', 'reason'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['rankings'],
                        'additionalProperties' => false,
                    ],
                    'json_schema_name' => 'image_rankings',
                ]
            );

            // OpenAiProvider returns decoded content directly
            $data = $response['content'] ?? null;
            if (!$data) {
                throw new \RuntimeException('Empty response from AI');
            }

            $rankings = $data['rankings'] ?? [];
            if (empty($rankings)) {
                throw new \RuntimeException('No rankings in AI response');
            }

            // Sort by score descending
            usort($rankings, fn($a, $b) => $b['score'] <=> $a['score']);

            // Take top N
            $topRankings = array_slice($rankings, 0, $topN);

            // Map rankings to actual images
            $selectedImages = [];
            foreach ($topRankings as $ranking) {
                $index = $ranking['index'];
                if (!isset($images[$index])) {
                    Log::warning('[ImageSelectionService] Invalid image index in ranking', [
                        'index' => $index,
                        'total_images' => count($images),
                    ]);
                    continue;
                }

                $image = $images[$index];
                $image['ai_score'] = $ranking['score'];
                $image['ai_reason'] = $ranking['reason'];
                $selectedImages[] = $image;
            }

            if (empty($selectedImages)) {
                throw new \RuntimeException('No valid images in rankings');
            }

            Log::info('[ImageSelectionService] Selection completed', [
                'selected_count' => count($selectedImages),
                'winner_score' => $selectedImages[0]['ai_score'] ?? null,
            ]);

            return [
                'winner' => $selectedImages[0],
                'alternatives' => array_slice($selectedImages, 1),
                'rankings' => $topRankings,
            ];

        } catch (\Exception $e) {
            Log::error('[ImageSelectionService] Selection failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Build prompt for AI evaluation
     */
    private function buildPrompt(string $description, array $images, int $topN): string
    {
        $imagesList = [];
        foreach ($images as $index => $image) {
            $imagesList[] = sprintf(
                "Index %d:\n  URL: %s\n  Description: %s\n  Author: %s",
                $index,
                $image['thumb_url'] ?? $image['url'],
                $image['description'] ?: '(no description)',
                $image['author'] ?? 'Unknown'
            );
        }

        $imagesText = implode("\n\n", $imagesList);

        return <<<PROMPT
You need to evaluate {$topN} best images for the following text description:

**Text Description:**
{$description}

**Available Images:**
{$imagesText}

**Evaluation Criteria:**
1. Relevance to the text description (most important)
2. Visual quality and professionalism
3. Suitability for educational/exam materials (avoid distracting elements)
4. Clarity and focus of the subject

**Task:**
Evaluate all images and return the top {$topN} images ranked by overall suitability.
For each image, provide:
- `index`: the image index (0-based)
- `score`: numerical score from 0 to 10 (decimals allowed)
- `reason`: brief explanation (1-2 sentences) why this image is suitable

Return the results in JSON format as specified in the schema.
PROMPT;
    }
}
