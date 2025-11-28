<?php

namespace App\Services\LanguageApp\Providers;

use App\Services\LanguageApp\AiRateLimiter;
use App\Services\LanguageApp\AsyncAiProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;

/**
 * Async OpenAI Provider using Guzzle Promises for non-blocking requests
 */
final class AsyncOpenAiProvider implements AsyncAiProvider
{
    private ?AiRateLimiter $rateLimiter = null;

    public function __construct(
        private readonly Client $http,
        private readonly string $apiKey,
        private readonly string $baseUrl, // @phpstan-ignore-line (reserved for future use)
        private readonly string $model
    ) {
        // Initialize rate limiter if enabled
        if (config('ai.rate_limit_enabled', false)) {
            $this->rateLimiter = new AiRateLimiter(
                maxRequestsPerMinute: config('ai.rate_limit_rpm', 60),
                maxRetries: config('ai.rate_limit_retries', 3),
                retryDelayMs: config('ai.rate_limit_retry_delay_ms', 1000)
            );
        }
    }

    /**
     * Generate AI response asynchronously using Guzzle promises
     */
    public function generateAsync(array $payload, array $opts = []): PromiseInterface
    {
        Log::debug('AsyncOpenAiProvider: generateAsync start', ['payload' => $payload, 'options' => $opts]);

        $cfg = config('ai');
        $openai_cfg = $cfg['openai'];

        // Support model override from opts or resolve alias
        $requestedModel = $opts['model'] ?? null;
        if ($requestedModel && isset($cfg['models'][$requestedModel])) {
            $model = $cfg['models'][$requestedModel];
        } else {
            $model = $requestedModel ?? $this->model ?? $openai_cfg['model'];
        }

        $baseMessages = $payload['messages'] ?? $payload['input'] ?? $payload;

        if (is_string($baseMessages)) {
            $baseMessages = [['role' => 'user', 'content' => $baseMessages]];
        }

        $messages = [];

        // 1. System message for JSON strict mode
        if ($openai_cfg['json_strict'] ?? false) {
            $messages[] = [
                'role' => 'system',
                'content' => 'Return ONLY valid JSON that matches the provided JSON schema. No prose, no markdown.',
            ];
        }

        // 2. Add baseMessages
        $messages = array_merge($messages, $baseMessages);

        // 3. System message for JSON schema
        $responseJsonSchema = $cfg['response_json_schema'] ?? null;
        if ($responseJsonSchema) {
            $messages[] = [
                'role' => 'system',
                'content' => 'Return JSON only matching the schema into response_json_schema. Your opinion important additions put into "additional_info" object.',
            ];
        }

        $body = [
            'model' => $model,
            'messages' => $messages,
        ];

        // Priority 1: Use Structured Outputs (json_schema) if provided in opts
        $jsonSchema = $opts['json_schema'] ?? null;
        if ($jsonSchema) {
            $body['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $opts['json_schema_name'] ?? 'response',
                    'strict' => true,
                    'schema' => $jsonSchema,
                ],
            ];

            Log::debug('AsyncOpenAiProvider: using Structured Outputs', [
                'schema_name' => $opts['json_schema_name'] ?? 'response',
            ]);
        }
        // Priority 2: Legacy response_json_schema (deprecated)
        elseif ($responseJsonSchema) {
            $body['response_format'] = ['type' => 'json_object'];
            $body['response_format']['response_json_schema'] = $responseJsonSchema;
        }
        // Priority 3: Basic json_object mode
        elseif ($openai_cfg['json_strict'] ?? false) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        Log::debug('AsyncOpenAiProvider: sending async request', ['model' => $model, 'messages_count' => count($messages)]);

        // Check rate limit before sending
        if ($this->rateLimiter && ! $this->rateLimiter->attemptWithRetry('openai')) {
            throw new \RuntimeException('OpenAI rate limit exceeded. Please try again later.');
        }

        // Send async request and return promise
        $promise = $this->http->postAsync('chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
        ]);

        // Transform promise to handle response parsing
        return $promise->then(
            function (ResponseInterface $response) use ($model, $opts, $messages) {
                return $this->parseResponse($response, $model, $opts, $messages);
            },
            function (\Exception $e) {
                Log::error('AsyncOpenAiProvider: request failed', ['error' => $e->getMessage()]);
                throw new \RuntimeException('AI HTTP error: '.$e->getMessage());
            }
        );
    }

    /**
     * Generate multiple AI responses in parallel
     */
    public function generateBatch(array $requests): PromiseInterface
    {
        Log::debug('AsyncOpenAiProvider: generateBatch start', ['count' => count($requests)]);

        $promises = [];
        foreach ($requests as $request) {
            $key = $request['key'] ?? uniqid('batch_', true);
            $payload = $request['payload'] ?? [];
            $opts = $request['opts'] ?? [];

            $promises[$key] = $this->generateAsync($payload, $opts);
        }

        // Wait for all promises to resolve
        return Utils::all($promises)->then(
            function (array $results) {
                Log::debug('AsyncOpenAiProvider: batch completed', ['count' => count($results)]);

                return $results;
            }
        );
    }

    /**
     * Parse HTTP response and extract AI content
     */
    private function parseResponse(ResponseInterface $response, string $model, array $opts, array $messages): array
    {
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        Log::debug('AsyncOpenAiProvider: response received', ['status' => $status]);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('AI non-2xx status: '.$status.' body: '.self::clip($raw));
        }

        $body = json_decode($raw, true) ?? [];
        if (! is_array($body) || ! isset($body['choices'][0]['message']['content'])) {
            throw new \RuntimeException('AI response is malformed: '.self::clip($raw));
        }

        $contentText = $body['choices'][0]['message']['content'];
        $content = json_decode($contentText, true);

        Log::debug('AsyncOpenAiProvider: response parsed', ['content_length' => strlen($contentText)]);

        if (! is_array($content)) {
            throw new \RuntimeException('AI returned non-JSON content: '.self::clip($contentText));
        }

        // Check for refusal (Structured Outputs safety feature)
        $refusal = $body['choices'][0]['message']['refusal'] ?? null;
        if ($refusal) {
            Log::warning('AsyncOpenAiProvider: AI refused to generate', ['refusal' => $refusal]);
            throw new \RuntimeException('AI refused to generate: '.$refusal);
        }

        return [
            'ok' => true,
            'raw' => $raw,
            'body' => $body,
            'content_text' => $contentText, // Keep original JSON string
            'content' => $content,          // Parsed object/array
            'usage' => $body['usage'] ?? ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
            'model' => $model,
            'model_alias' => $opts['model'] ?? null,
            'sent_messages' => $messages,
            'json_schema_used' => isset($opts['json_schema']), // Flag for downstream processing
        ];
    }

    private static function clip(string $s, int $len = 800): string
    {
        $s = trim($s);

        return mb_strlen($s) > $len ? (mb_substr($s, 0, $len).'…') : $s;
    }
}
