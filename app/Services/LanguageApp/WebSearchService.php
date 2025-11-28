<?php

namespace App\Services\LanguageApp;

use Illuminate\Support\Facades\Log;

/**
 * Web Search Service
 *
 * Performs web searches to gather information about exams
 * Uses AI to summarize search results into actionable insights
 */
class WebSearchService extends AbstractAiService
{
    /**
     * Search for information and return summarized results
     */
    public function search(string $query): array
    {
        try {
            // Use AI to generate a search summary based on the query
            // For now, we'll use a simplified approach - in production,
            // this would integrate with actual search APIs (Google, Bing, etc.)

            $aiPayload = $this->buildSearchPayload($query);

            $response = $this->ai->generate($aiPayload, []);

            $content = $this->extractSearchContent($response);

            return [
                'query' => $query,
                'summary' => $content,
                'source' => 'ai_knowledge',
            ];
        } catch (\Throwable $e) {
            Log::error('Web search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [
                'query' => $query,
                'summary' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build AI payload for search query
     */
    protected function buildSearchPayload(string $query): array
    {
        $systemPrompt = <<<'SYSTEM'
You are an exam information specialist. When given a search query about an exam,
provide concise, factual information based on your knowledge.

Focus on:
- Official exam names and codes
- Exam structure (sections, timing, question counts)
- Exam variants and their differences
- CEFR levels if applicable
- Official providers and administering bodies

Keep responses under 200 words. Be precise and cite official sources when possible.
SYSTEM;

        $userPrompt = <<<PROMPT
Search query: "{$query}"

Please provide relevant, factual information about this exam topic.
Be specific about exam structures, variants, and official details.
PROMPT;

        return [
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
                [
                    'role' => 'user',
                    'content' => $userPrompt,
                ],
            ],
        ];
    }

    /**
     * Extract content from AI response
     * Tries to decode JSON strings for better readability
     */
    protected function extractSearchContent(array $aiResponse): mixed
    {
        $content = null;

        if (isset($aiResponse['content'])) {
            $content = is_string($aiResponse['content'])
                ? $aiResponse['content']
                : json_encode($aiResponse['content']);
        } elseif (isset($aiResponse['body']['choices'][0]['message']['content'])) {
            $content = $aiResponse['body']['choices'][0]['message']['content'];
        }

        // Try to decode JSON for better readability
        if (is_string($content)) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return $content;
    }
}
