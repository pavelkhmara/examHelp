<?php

namespace App\Services\LanguageApp\Providers;

use App\Services\LanguageApp\AiProvider;
use App\Services\LanguageApp\AiRateLimiter;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

final class OpenAiProvider implements AiProvider
{
    private ?AiRateLimiter $rateLimiter = null;

    public function __construct(
        private readonly Client $http,
        private readonly string $apiKey,
        private readonly string $baseUrl,
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
        // $this->http = new \GuzzleHttp\Client([
        //     'base_uri'        => rtrim(config('ai.openai.base_url'), '/').'/',
        //     'headers'         => ['Authorization' => 'Bearer '.config('ai.openai.api_key')],
        //     'timeout'         => 90,           // общий таймаут
        //     'connect_timeout' => 10,           // соединение
        //     'read_timeout'    => 80,
        // ]);

        // $handler = \GuzzleHttp\HandlerStack::create();
        // $handler->push(\GuzzleHttp\Middleware::retry(
        //     function ($retries, $request, $response, $exception) {
        //         if ($retries >= 2) return false;
        //         if ($exception instanceof \GuzzleHttp\Exception\ConnectException) return true;
        //         if ($exception instanceof \GuzzleHttp\Exception\RequestException && $exception->getHandlerContext()['errno'] === 28) return true; // cURL 28
        //         if ($response && in_array($response->getStatusCode(), [429, 500, 502, 503, 504])) return true;
        //         return false;
        //     },
        //     function ($retries) { return 1000 * (2 ** $retries); } // 1s, 2s
        // ));
        // $this->http = new \GuzzleHttp\Client(['handler' => $handler] + $this->http->getConfig());
    }

    public function generate(array $payload, array $opts = []): array
    {
        Log::debug('OpenAiProvider: generate start', ['payload' => $payload, 'options' => $opts]);

        $cfg = config('ai');
        $openai_cfg = $cfg['openai'];

        // Support model override from opts or resolve alias
        $requestedModel = $opts['model'] ?? null;
        if ($requestedModel && isset($cfg['models'][$requestedModel])) {
            // Resolve alias (e.g., 'thinking' -> 'gpt-5')
            $model = $cfg['models'][$requestedModel];
        } else {
            $model = $requestedModel ?? $this->model ?? $openai_cfg['model'];
        }

        $baseMessages = $payload['messages'] ?? $payload['input'] ?? $payload;

        if (is_string($baseMessages)) {
            $baseMessages = [['role' => 'user', 'content' => $baseMessages]];
        }

        // Support file attachments via opts['file_ids']
        $fileIds = $opts['file_ids'] ?? [];
        if (!empty($fileIds) && !empty($baseMessages)) {
            // Transform last user message to include file attachments
            $lastIdx = count($baseMessages) - 1;
            $lastMsg = $baseMessages[$lastIdx];
            if (($lastMsg['role'] ?? '') === 'user' && is_string($lastMsg['content'] ?? null)) {
                $contentParts = [['type' => 'text', 'text' => $lastMsg['content']]];
                foreach ($fileIds as $fileId) {
                    $contentParts[] = [
                        'type' => 'file',
                        'file' => ['file_id' => $fileId],
                    ];
                }
                $baseMessages[$lastIdx]['content'] = $contentParts;
            }
        }

        $messages = [];

        // 1. System message for JSON strict mode
        if ($openai_cfg['json_strict'] ?? false) {
            $messages[] = [
                'role' => 'system',
                'content' => 'Return ONLY valid JSON that matches the provided JSON schema. No prose, no markdown.',
            ];
        }

        // 2. Add baseMessages (user/assistant)
        $messages = array_merge($messages, $baseMessages);

        // 3. System message for JSON schema (if exist)
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

        // Support Structured Outputs via json_schema option
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
        } elseif ($openai_cfg['json_strict'] ?? false) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        if ($responseJsonSchema && !$jsonSchema) {
            $body['response_format'] = $body['response_format'] ?? ['type' => 'json_object'];
            $body['response_format']['response_json_schema'] = $responseJsonSchema;
        }

        Log::debug('OpenAiProvider: final request body', ['body' => $body]);

        
        // Retry logic for 5xx errors, timeouts, and connection issues
        $maxRetries = 3;
        $attempt = 0;
        $lastException = null;
        
        // Check rate limit before sending
        if ($this->rateLimiter && ! $this->rateLimiter->attemptWithRetry('openai')) {
            throw new \RuntimeException('OpenAI rate limit exceeded. Please try again later.');
        }

        while ($attempt < $maxRetries) {
            $attempt++;

            try {
                Log::debug('OpenAiProvider: attempt', ['attempt' => $attempt, 'max_retries' => $maxRetries]);

                $res = $this->http->post('chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $body,
                ]);

                // Success - break retry loop
                break;

            } catch (GuzzleException $e) {
                $lastException = $e;
                $shouldRetry = false;
                $retryReason = null;

                // Check if this is a retryable error
                if ($e instanceof \GuzzleHttp\Exception\ConnectException) {
                    $shouldRetry = true;
                    $retryReason = 'connection_error';
                } elseif ($e instanceof \GuzzleHttp\Exception\RequestException) {
                    // Check for timeout (cURL error 28)
                    $handlerContext = $e->getHandlerContext();
                    if (isset($handlerContext['errno']) && $handlerContext['errno'] === 28) {
                        $shouldRetry = true;
                        $retryReason = 'timeout';
                    }

                    // Check for 5xx server errors
                    if ($e->hasResponse()) {
                        $statusCode = $e->getResponse()->getStatusCode();
                        if ($statusCode >= 500 && $statusCode < 600) {
                            $shouldRetry = true;
                            $retryReason = "server_error_{$statusCode}";
                        }
                    }
                }

                // If not retryable or last attempt, throw exception
                if (!$shouldRetry || $attempt >= $maxRetries) {
                    Log::error('OpenAiProvider: request failed', [
                        'attempt' => $attempt,
                        'max_retries' => $maxRetries,
                        'error' => $e->getMessage(),
                        'retryable' => $shouldRetry,
                        'retry_reason' => $retryReason,
                    ]);

                    throw new \RuntimeException('AI HTTP error: '.$e->getMessage());
                }

                // Calculate exponential backoff delay (1s, 2s, 4s)
                $delayMs = 1000 * (2 ** ($attempt - 1));

                Log::warning('OpenAiProvider: retrying after error', [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'retry_reason' => $retryReason,
                    'delay_ms' => $delayMs,
                    'error' => $e->getMessage(),
                ]);

                // Wait before retrying
                usleep($delayMs * 1000); // Convert ms to microseconds
            }
        }

        // If we exited the loop without a response, throw the last exception
        if (!isset($res)) {
            throw new \RuntimeException('AI HTTP error after '.$maxRetries.' retries: '.($lastException ? $lastException->getMessage() : 'unknown error'));
        }

        $status = $res->getStatusCode();
        $raw = (string) $res->getBody();

        Log::debug('AiProviderFactory: response status ►', ['status' => $status]);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('AI non-2xx status: '.$status.' body: '.self::clip($raw));
        }

        $body = json_decode($raw, true) ?? [];
        if (! is_array($body) || ! isset($body['choices'][0]['message']['content'])) {
            throw new \RuntimeException('AI response is malformed: '.self::clip($raw));
        }

        $contentText = $body['choices'][0]['message']['content'];
        $content = json_decode($contentText, true);

        Log::debug('AiProviderFactory: response FULL ►', ['body' => $body, 'content' => $content, 'contentText' => $contentText]);

        if (! is_array($content)) {
            throw new \RuntimeException('AI returned non-JSON content: '.self::clip($contentText));
        }

        return [
            'ok' => true,
            'raw' => $raw,                 // сырое тело HTTP-ответа провайдера
            'body' => $body,                // декодированный top-level JSON провайдера
            'content_text' => $contentText,         // строка JSON внутри message.content
            'content' => $content,             // ДЕКОДИРОВАННЫЙ overview-объект — используем дальше в сервисе
            'usage' => $body['usage'] ?? ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
            'model' => $model,                 // Модель использованная для запроса (для логов)
            'model_alias' => $opts['model'] ?? null, // Алиас модели (thinking, default, etc.)
            'sent_messages' => $messages,      // Final messages sent to AI (for logging)
        ];
    }

    private static function clip(string $s, int $len = 800): string
    {
        $s = trim($s);

        return mb_strlen($s) > $len ? (mb_substr($s, 0, $len).'…') : $s;
    }

    /**
     * Upload a file to OpenAI Files API for use with chat completions.
     *
     * @param string $filePath Local file path
     * @param string $filename Original filename
     * @param string $purpose Purpose: 'assistants' for file attachments
     * @return array{ok: bool, file_id?: string, error?: string}
     */
    public function uploadFile(string $filePath, string $filename, string $purpose = 'assistants'): array
    {
        Log::debug('OpenAiProvider: uploadFile start', ['path' => $filePath, 'filename' => $filename]);

        try {
            $res = $this->http->post('files', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                ],
                'multipart' => [
                    [
                        'name' => 'purpose',
                        'contents' => $purpose,
                    ],
                    [
                        'name' => 'file',
                        'contents' => fopen($filePath, 'r'),
                        'filename' => $filename,
                    ],
                ],
            ]);

            $body = json_decode((string) $res->getBody(), true);

            if (!isset($body['id'])) {
                return ['ok' => false, 'error' => 'No file_id in response'];
            }

            Log::info('OpenAiProvider: file uploaded', ['file_id' => $body['id']]);

            return ['ok' => true, 'file_id' => $body['id']];

        } catch (GuzzleException $e) {
            Log::error('OpenAiProvider: uploadFile failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete a file from OpenAI Files API.
     *
     * @param string $fileId OpenAI file ID
     * @return array{ok: bool, error?: string}
     */
    public function deleteFile(string $fileId): array
    {
        Log::debug('OpenAiProvider: deleteFile', ['file_id' => $fileId]);

        try {
            $res = $this->http->delete("files/{$fileId}", [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                ],
            ]);

            $body = json_decode((string) $res->getBody(), true);

            if (($body['deleted'] ?? false) === true) {
                Log::info('OpenAiProvider: file deleted', ['file_id' => $fileId]);
                return ['ok' => true];
            }

            return ['ok' => false, 'error' => 'File not deleted'];

        } catch (GuzzleException $e) {
            Log::error('OpenAiProvider: deleteFile failed', ['file_id' => $fileId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
