<?php

declare(strict_types=1);

namespace App\Services\LanguageApp;

use App\Models\ExamExampleQuestion;
use App\Models\Question;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Question Image Processor
 *
 * Processes questions and generates images where necessary
 * based on question type and content
 */
class QuestionImageProcessor
{
    public function __construct(
        private readonly ImageSearchService $imageSearch,
        private readonly ImageSelectionService $imageSelection
    ) {}

    /**
     * Process questions and generate images where necessary
     *
     * @param  array<int, ExamExampleQuestion|Question>  $questions
     * @return array{processed: int, images_generated: int, errors: int}
     */
    public function processQuestions(array $questions): array
    {
        $processed = 0;
        $imagesGenerated = 0;
        $errors = 0;

        foreach ($questions as $question) {
            try {
                $generated = $this->processQuestion($question);
                $processed++;
                if ($generated) {
                    $imagesGenerated++;
                }
            } catch (\Exception $e) {
                $errors++;
                Log::error('[QuestionImageProcessor] Failed to process question', [
                    'question_id' => $question->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[QuestionImageProcessor] Batch processing complete', [
            'processed' => $processed,
            'images_generated' => $imagesGenerated,
            'errors' => $errors,
        ]);

        return [
            'processed' => $processed,
            'images_generated' => $imagesGenerated,
            'errors' => $errors,
        ];
    }

    /**
     * Process one question and generate image if necessary
     *
     * @return bool True if image was generated
     */
    public function processQuestion(ExamExampleQuestion|Question $question): bool
    {
        // Check if image generation is needed
        if (! $this->shouldGenerateImage($question)) {
            return false;
        }

        // Determine what text to use for image search
        $searchText = $this->getTextForImageSearch($question);
        if (empty($searchText)) {
            Log::debug('[QuestionImageProcessor] No text for image search', [
                'question_id' => $question->id,
            ]);

            return false;
        }

        try {
            // Step 1: Search for images
            $images = $this->imageSearch->searchImages($searchText);
            if (empty($images)) {
                Log::warning('[QuestionImageProcessor] No images found', [
                    'question_id' => $question->id,
                    'search_text' => $searchText,
                ]);

                return false;
            }

            // Step 2: Select best images using AI
            $selection = $this->imageSelection->selectImages($searchText, $images);

            // Step 3: Download and save winner image
            $winner = $selection['winner'];
            $downloadedPath = $this->downloadImage($winner['url'], $question->id);

            // Step 4: Update question
            DB::transaction(function () use ($question, $winner, $selection, $downloadedPath, $searchText) {
                $question->image_url = $winner['url'];
                $question->image_file_path = $downloadedPath;

                // Store alternatives in test mode
                if (config('ai.images.test_mode', true)) {
                    $question->image_alternatives = $selection['alternatives'];
                }

                // Store metadata
                $question->image_metadata = [
                    'provider' => $winner['source'] ?? null,
                    'author' => $winner['author'] ?? null,
                    'author_url' => $winner['author_url'] ?? null,
                    'license' => $winner['license'] ?? null,
                    'ai_score' => $winner['ai_score'] ?? null,
                    'ai_reason' => $winner['ai_reason'] ?? null,
                    'search_text' => $searchText,
                    'generated_at' => now()->toIso8601String(),
                ];

                $question->save();
            });

            Log::info('[QuestionImageProcessor] Image generated for question', [
                'question_id' => $question->id,
                'image_url' => $winner['url'],
                'image_path' => $downloadedPath,
                'ai_score' => $winner['ai_score'] ?? null,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('[QuestionImageProcessor] Failed to generate image', [
                'question_id' => $question->id,
                'search_text' => $searchText,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Determine if image generation is needed for this question
     */
    protected function shouldGenerateImage(ExamExampleQuestion|Question $question): bool
    {
        // If image generation is disabled globally
        if (! config('ai.images.enabled', false)) {
            return false;
        }

        // If image already exists
        if (! empty($question->image_url) || ! empty($question->image_file_path)) {
            return false;
        }

        // Handle ExamExampleQuestion (uses payload)
        if ($question instanceof ExamExampleQuestion) {
            $payload = $question->payload ?? [];

            // Check for explicit image requirement flags
            if (isset($payload['requires_image']) && $payload['requires_image'] === true) {
                return true;
            }

            // Check if stimulus has image_description
            if (isset($payload['stimulus']['image_description'])) {
                return true;
            }

            // Check metadata tags
            $metadata = $payload['metadata'] ?? [];
            $tags = $metadata['tags'] ?? [];
            if (in_array('image', $tags, true) || in_array('visual', $tags, true)) {
                return true;
            }
        }

        // Handle Question (v2 structure with stimulus and metadata as top-level fields)
        if ($question instanceof Question) {
            $stimulus = $question->stimulus ?? [];

            // Check if stimulus has image_description
            if (isset($stimulus['image_description'])) {
                return true;
            }

            // Check metadata tags
            $metadata = $question->metadata ?? [];
            $tags = $metadata['tags'] ?? [];
            if (in_array('image', $tags, true) || in_array('visual', $tags, true)) {
                return true;
            }

            // Check if stimulus has images array (might need replacement)
            if (isset($stimulus['images']) && is_array($stimulus['images']) && ! empty($stimulus['images'])) {
                // Already has images, but might be placeholders
                foreach ($stimulus['images'] as $img) {
                    if (isset($img['generate']) && $img['generate'] === true) {
                        return true;
                    }
                }
            }
        }

        // For now, we don't auto-generate images for other types
        // User must explicitly mark questions as needing images
        return false;
    }

    /**
     * Extract text for image search
     */
    protected function getTextForImageSearch(ExamExampleQuestion|Question $question): ?string
    {
        // Handle ExamExampleQuestion
        if ($question instanceof ExamExampleQuestion) {
            $payload = $question->payload ?? [];

            // Priority 1: Explicit image_description in stimulus
            if (isset($payload['stimulus']['image_description'])) {
                return $payload['stimulus']['image_description'];
            }

            // Priority 2: stimulus.text (if it's descriptive)
            if (isset($payload['stimulus']['text']) && ! empty($payload['stimulus']['text'])) {
                $text = $payload['stimulus']['text'];
                // Only use if it's descriptive (not too long, not too short)
                if (mb_strlen($text) >= 10 && mb_strlen($text) <= 300) {
                    return $text;
                }
            }

            // Priority 3: question description field
            if (! empty($question->description)) {
                return $question->description;
            }

            // Priority 4: question field itself
            if (! empty($question->question)) {
                return $question->question;
            }
        }

        // Handle Question (v2)
        if ($question instanceof Question) {
            $stimulus = $question->stimulus ?? [];

            // Priority 1: Explicit image_description in stimulus
            if (isset($stimulus['image_description'])) {
                return $stimulus['image_description'];
            }

            // Priority 2: stimulus.text_html (strip tags)
            if (isset($stimulus['text_html']) && ! empty($stimulus['text_html'])) {
                $text = strip_tags($stimulus['text_html']);
                // Only use if it's descriptive (not too long, not too short)
                if (mb_strlen($text) >= 10 && mb_strlen($text) <= 300) {
                    return $text;
                }
            }

            // Priority 3: metadata.topic
            $metadata = $question->metadata ?? [];
            if (! empty($metadata['topic'])) {
                return $metadata['topic'];
            }

            // Priority 4: question_id (last resort)
            if (! empty($question->question_id)) {
                return $question->question_id;
            }
        }

        return null;
    }

    /**
     * Download image and save to storage
     *
     * @return string|null Relative path to saved file
     */
    protected function downloadImage(string $url, int $questionId): ?string
    {
        try {
            Log::info('[QuestionImageProcessor] Downloading image', [
                'url' => $url,
                'question_id' => $questionId,
            ]);

            $response = Http::timeout(30)->get($url);

            if (! $response->successful()) {
                Log::error('[QuestionImageProcessor] Failed to download image', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $imageContent = $response->body();

            // Determine file extension from content type or URL
            $contentType = $response->header('Content-Type');
            $extension = match ($contentType) {
                'image/jpeg', 'image/jpg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };

            // Generate filename
            $filename = 'q_'.$questionId.'_'.md5($url).'.'.$extension;
            $relativePath = 'images/'.date('Y/m/d').'/'.$filename;

            // Save to storage
            Storage::disk('public')->put($relativePath, $imageContent);

            Log::info('[QuestionImageProcessor] Image downloaded', [
                'path' => $relativePath,
                'size_bytes' => strlen($imageContent),
            ]);

            return $relativePath;

        } catch (\Exception $e) {
            Log::error('[QuestionImageProcessor] Failed to download image', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Delete image file
     */
    public function deleteImage(string $path): bool
    {
        if (! $path) {
            return false;
        }

        try {
            return Storage::disk('public')->delete($path);
        } catch (\Exception $e) {
            Log::error('[QuestionImageProcessor] Failed to delete image', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if image file exists
     */
    public function imageExists(string $path): bool
    {
        if (! $path) {
            return false;
        }

        return Storage::disk('public')->exists($path);
    }

    /**
     * Get URL for image file
     */
    public function getImageUrl(string $path): ?string
    {
        if (! $path || ! $this->imageExists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
