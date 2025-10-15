<?php

namespace App\Services;

use GuzzleHttp\Client;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class AbstractAiService {

    protected Client $client;
    protected string $model;
    protected string $provider;

    // OpenAI Models
    const MODEL_GPT_4o = 'gpt-4o-2024-08-06';
    const MODEL_GPT_4o_MINI = 'gpt-4o-mini';
    const MODEL_GPT_4_1 = 'gpt-4.1';
    const MODEL_GPT_4_1_MINI = 'gpt-4.1-mini';
    const MODEL_GPT_4_1_NANO = 'gpt-4.1-nano';
    const MODEL_o4_MINI = 'o4-mini';
    const MODEL_o3 = 'o3';
    const MODEL_GPT_5 = 'gpt-5';
    const MODEL_GPT_5_MINI = 'gpt-5-mini';

    // Anthropic Models
    const MODEL_CLAUDE_HAIKU_35 = 'claude-3-5-haiku-20241022';
    const MODEL_CLAUDE_SONNET_4 = 'claude-sonnet-4-20250514';
    const MODEL_CLAUDE_OPUS_4 = 'claude-opus-4-20250514';

    // DeepSeek Models
    const MODEL_DEEPSEEK_CHAT = 'deepseek-chat';
    const MODEL_DEEPSEEK_REASONER = 'deepseek-reasoner';

    const MODEL_GEMINI_2_5_FLASH = 'gemini-2.5-flash';
    const MODEL_GEMINI_2_5_PRO = 'gemini-2.5-pro';
    const MODEL_GEMINI_2_5_FLASH_IMAGE = 'gemini-2.5-flash-image-preview';

    const PROVIDER_OPENAI = 'openai';
    const PROVIDER_ANTHROPIC = 'anthropic';
    const PROVIDER_DEEPSEEK = 'deepseek';
    const PROVIDER_GOOGLE = 'google';

    public function __construct($provider = self::PROVIDER_OPENAI)
    {
        $this->setProvider($provider);
    }

    /**
     * @throws Exception
     */
    public function setProvider(string $provider): void
    {
        $this->provider = $provider;

        if (self::PROVIDER_ANTHROPIC === $this->provider) {
            $this->client = new Client([
                'base_uri' => 'https://api.anthropic.com/',
                'headers' => [
                    'Content-Type' => 'application/json',
                    'anthropic-version' => '2023-06-01',
                    'x-api-key' => config('ai-tools.anthropic.key'),
                ],
                'connect_timeout' => 30,
                'timeout' => 300,
                'read_timeout' => 300
            ]);
        } elseif (self::PROVIDER_OPENAI === $this->provider) {
            $this->client = new Client([
                'base_uri' => 'https://api.openai.com/',
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . config('ai-tools.open-ai.key'),
                    'OpenAI-Beta' => 'assistants=v2',
                ],
                'connect_timeout' => 30,
                'timeout' => 300,
                'read_timeout' => 300
            ]);
        } elseif (self::PROVIDER_DEEPSEEK === $this->provider) {
            $this->client = new Client([
                'base_uri' => 'https://api.deepseek.com/',
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . env('DEEPSEEK_API_KEY'),
                ],
                'connect_timeout' => 30,
                'timeout' => 600,
                'read_timeout' => 600
            ]);
        } elseif (self::PROVIDER_GOOGLE === $this->provider) {
            $this->client = new Client([
                'base_uri' => 'https://generativelanguage.googleapis.com/',
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'connect_timeout' => 30,
                'timeout' => 600,
                'read_timeout' => 600
            ]);
        } else {
            throw new Exception("Unsupported provider: $provider");
        }

        Log::info("HTTP client configured for provider", [
            'provider' => $provider,
            'connect_timeout' => $provider === self::PROVIDER_ANTHROPIC || $provider === self::PROVIDER_OPENAI ? 30 : 30,
            'timeout' => $provider === self::PROVIDER_ANTHROPIC || $provider === self::PROVIDER_OPENAI ? 300 : 600,
            'read_timeout' => $provider === self::PROVIDER_ANTHROPIC || $provider === self::PROVIDER_OPENAI ? 300 : 600
        ]);
    }

    public function setModel(string $model): void
    {
        $this->model = $model;
    }

    public static function getAvailableProviders(): array
    {
        return [
            self::PROVIDER_OPENAI => 'OpenAI',
            self::PROVIDER_ANTHROPIC => 'Anthropic',
            self::PROVIDER_DEEPSEEK => 'DeepSeek',
            self::PROVIDER_GOOGLE => 'Google Gemini',
        ];
    }

    public static function getModelsForProvider(string $provider): array
    {
        if ($provider === self::PROVIDER_OPENAI) {
            return [
                self::MODEL_GPT_4_1 => 'GPT-4.1',
                self::MODEL_GPT_4_1_MINI => 'GPT-4.1 MINI',
                self::MODEL_GPT_4_1_NANO => 'GPT-4.1 NANO',
                self::MODEL_o4_MINI => 'o4 mini',
                self::MODEL_o3 => 'o3 - VERY EXPENSIVE',
                self::MODEL_GPT_5 => 'GPT-5',
                self::MODEL_GPT_5_MINI => 'GPT-5 Mini',
            ];
        }

        if ($provider === self::PROVIDER_ANTHROPIC) {
            return [
                self::MODEL_CLAUDE_HAIKU_35 => 'Claude 3.5 Haiku',
                self::MODEL_CLAUDE_SONNET_4 => 'Claude Sonnet 4 - LATEST',
                self::MODEL_CLAUDE_OPUS_4 => 'Claude Opus 4 - VERY EXPENSIVE',
            ];
        }

        if ($provider === self::PROVIDER_DEEPSEEK) {
            return [
                self::MODEL_DEEPSEEK_CHAT => 'DeepSeek Chat',
                self::MODEL_DEEPSEEK_REASONER => 'DeepSeek Reasoner',
            ];
        }

        if ($provider === self::PROVIDER_GOOGLE) {
            return [
                self::MODEL_GEMINI_2_5_FLASH => 'Gemini 2.5 Flash',
                self::MODEL_GEMINI_2_5_PRO => 'Gemini 2.5 Pro',
            ];
        }

        return [];
    }


    public function getCompletion(string $prompt, ?array $schema = null): string
    {
        if ($this->provider === self::PROVIDER_OPENAI) {
            return $this->getOpenAiCompletion($prompt, $schema);
        }

        if ($this->provider === self::PROVIDER_ANTHROPIC) {
            return $this->getAnthropicCompletion($prompt);
        }

        if ($this->provider === self::PROVIDER_DEEPSEEK) {
            return $this->getDeepSeekCompletion($prompt);
        }

        if ($this->provider === self::PROVIDER_GOOGLE) {
            return $this->getGoogleCompletion($prompt, $schema);
        }

        throw new Exception("Unsupported provider: " . $this->provider);
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    private function getOpenAiCompletion(string $prompt, ?array $schema = null): string
    {
        $requestStartTime = microtime(true);
        $promptSizeKb = round(strlen($prompt) / 1024, 2);

        Log::info("Starting OpenAI API call", [
            'model' => $this->model,
            'prompt_size_kb' => $promptSizeKb,
            'start_time' => date('Y-m-d H:i:s'),
            'has_schema' => $schema !== null,
        ]);

        // Use Chat Completions API for regular requests
        $requestData = [
            'json' => [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ]
            ]
        ];

        // Add structured output schema if provided
        if ($schema !== null) {
            $requestData['json']['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => $schema
            ];
        }

        try {
            $response = $this->client->post("v1/chat/completions", $requestData);
            $requestDuration = microtime(true) - $requestStartTime;

            Log::info("OpenAI API response received", [
                'status_code' => $response->getStatusCode(),
                'request_duration_seconds' => round($requestDuration, 2),
                'response_size_kb' => round(strlen($response->getBody()) / 1024, 2)
            ]);

            if ($response->getStatusCode() !== 200) {
                $errorBody = $response->getBody()->getContents();
                Log::error("OpenAI API error response", [
                    'status_code' => $response->getStatusCode(),
                    'error_body' => $errorBody
                ]);
                throw new Exception("Error occurred during OpenAI API call: " . $errorBody);
            }

            $responseData = json_decode($response->getBody(), true);
            $content = $responseData['choices'][0]['message']['content'] ?? '';

            Log::info("OpenAI API call completed successfully", [
                'content_size_kb' => round(strlen($content) / 1024, 2),
                'has_choices' => isset($responseData['choices']),
                'choices_count' => count($responseData['choices'] ?? [])
            ]);

            return $content;

        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            $requestDuration = microtime(true) - $requestStartTime;

            $errorData = [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'request_duration_seconds' => round($requestDuration, 2),
                'prompt_size_kb' => $promptSizeKb,
                'model' => $this->model,
            ];

            if ($e instanceof \GuzzleHttp\Exception\ClientException || $e instanceof \GuzzleHttp\Exception\ServerException) {
                $response = $e->getResponse();
                if ($response) {
                    $errorData['http_status_code'] = $response->getStatusCode();
                    $errorData['response_body'] = $response->getBody()->getContents();
                    $errorData['response_headers'] = $response->getHeaders();
                }
            }

            Log::error("OpenAI API Guzzle exception", $errorData);

            throw new Exception("OpenAI API request failed: " . $e->getMessage());
        }
    }

    private function getAnthropicCompletion(string $prompt): string
    {
        $promptSizeKb = round(strlen($prompt) / 1024, 2);

        Log::info("Starting Anthropic API call", [
            'model' => $this->model,
            'prompt_size_kb' => $promptSizeKb,
            'start_time' => date('Y-m-d H:i:s')
        ]);

        $requestData = [
            'json' => [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ],
                'max_tokens' => $this->model === self::MODEL_CLAUDE_HAIKU_35 ? 4000 : 32000
            ]
        ];

        try {
            $requestStartTime = microtime(true);
            $response = $this->client->post("v1/messages", $requestData);
            $requestDuration = microtime(true) - $requestStartTime;

            Log::info("Anthropic API response received", [
                'status_code' => $response->getStatusCode(),
                'request_duration_seconds' => round($requestDuration, 2),
                'response_size_kb' => round(strlen($response->getBody()) / 1024, 2)
            ]);

            if ($response->getStatusCode() !== 200) {
                $errorBody = $response->getBody()->getContents();
                Log::error("Anthropic API error response", [
                    'status_code' => $response->getStatusCode(),
                    'error_body' => $errorBody
                ]);
                throw new Exception("Error occurred during Anthropic API call: " . $errorBody);
            }

            $responseData = json_decode($response->getBody(), true);
            $content = $responseData["content"][0]["text"] ?? '';

            Log::info("Anthropic API call completed successfully", [
                'content_size_kb' => round(strlen($content) / 1024, 2),
                'has_content' => isset($responseData['content']),
                'content_count' => count($responseData['content'] ?? [])
            ]);

            return $content;

        } catch (GuzzleException $e) {
            $requestDuration = microtime(true) - ($requestStartTime ?? microtime(true));

            Log::error("Anthropic API Guzzle exception", [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'request_duration_seconds' => round($requestDuration, 2),
                'prompt_size_kb' => $promptSizeKb
            ]);

            throw new Exception("Anthropic API request failed: " . $e->getMessage());
        }
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    private function getDeepSeekCompletion(string $prompt): string
    {
        $promptSizeKb = round(strlen($prompt) / 1024, 2);

        Log::info("Starting DeepSeek API call", [
            'model' => $this->model,
            'prompt_size_kb' => $promptSizeKb,
            'start_time' => date('Y-m-d H:i:s')
        ]);

        $requestData = [
            'json' => [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'stream' => false
            ]
        ];

        try {
            $requestStartTime = microtime(true);
            $response = $this->client->post("chat/completions", $requestData);
            $requestDuration = microtime(true) - $requestStartTime;

            Log::info("DeepSeek API response received", [
                'status_code' => $response->getStatusCode(),
                'request_duration_seconds' => round($requestDuration, 2),
                'response_size_kb' => round(strlen($response->getBody()) / 1024, 2)
            ]);

            if ($response->getStatusCode() !== 200) {
                $errorBody = $response->getBody()->getContents();
                Log::error("DeepSeek API error response", [
                    'status_code' => $response->getStatusCode(),
                    'error_body' => $errorBody
                ]);
                throw new Exception("Error occurred during DeepSeek API call: " . $errorBody);
            }

            $responseData = json_decode($response->getBody(), true);
            $content = $responseData['choices'][0]['message']['content'] ?? '';

            Log::info("DeepSeek API call completed successfully", [
                'content_size_kb' => round(strlen($content) / 1024, 2),
                'has_choices' => isset($responseData['choices']),
                'choices_count' => count($responseData['choices'] ?? [])
            ]);

            return $content;

        } catch (GuzzleException $e) {
            $requestDuration = microtime(true) - ($requestStartTime ?? microtime(true));

            Log::error("DeepSeek API Guzzle exception", [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'request_duration_seconds' => round($requestDuration, 2),
                'prompt_size_kb' => $promptSizeKb
            ]);

            throw new Exception("DeepSeek API request failed: " . $e->getMessage());
        }
    }

        /**
     * @throws GuzzleException
     * @throws Exception
     */
    private function getGoogleCompletion(string $prompt, ?array $schema = null): string
    {
        $requestStartTime = microtime(true);

        $promptSizeKb = round(strlen($prompt) / 1024, 2);
        $apiKey = env('GOOGLE_GEMINI_API_KEY');

        Log::info("Starting Google Gemini API call", [
            'model' => $this->model,
            'prompt_size_kb' => $promptSizeKb,
            'start_time' => date('Y-m-d H:i:s'),
            'has_api_key' => !empty($apiKey),
            'has_schema' => $schema !== null,
        ]);

        $requestData = [
            'json' => [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ]
            ]
        ];

        // Add structured output configuration if schema is provided
        if ($schema !== null) {
            $requestData['json']['generationConfig'] = [
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema
            ];
        }

        try {
            $response = $this->client->post("v1beta/models/{$this->model}:generateContent?key={$apiKey}", $requestData);
            $requestDuration = microtime(true) - $requestStartTime;

            Log::info("Google Gemini API response received", [
                'status_code' => $response->getStatusCode(),
                'request_duration_seconds' => round($requestDuration, 2),
                'response_size_kb' => round(strlen($response->getBody()) / 1024, 2)
            ]);

            if ($response->getStatusCode() !== 200) {
                $errorBody = $response->getBody()->getContents();
                Log::error("Google Gemini API error response", [
                    'status_code' => $response->getStatusCode(),
                    'error_body' => $errorBody
                ]);
                throw new Exception("Error occurred during Google API call: " . $errorBody);
            }

            $responseData = json_decode($response->getBody(), true);
            $content = '';

            if (isset($responseData['candidates'][0]['content']['parts'])) {
                $content = $responseData["candidates"][0]["content"]["parts"][0]['text'];
            } else {
                throw new Exception('No content found in Google Gemini response');
            }

            Log::info("Google Gemini API call completed successfully", [
                'content_size_kb' => round(strlen($content) / 1024, 2),
                'has_candidates' => isset($responseData['candidates']),
                'candidates_count' => count($responseData['candidates'] ?? [])
            ]);

            return $content;

        } catch (GuzzleException $e) {
            $requestDuration = microtime(true) - ($requestStartTime);

            Log::error("Google Gemini API Guzzle exception", [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'request_duration_seconds' => round($requestDuration, 2),
                'prompt_size_kb' => $promptSizeKb
            ]);

            throw new Exception("Google Gemini API request failed: " . $e->getMessage());
        }
    }

    public function getCompletionWithImages(array $messages): string
    {
        if ($this->provider === self::PROVIDER_OPENAI) {
            $requestData = [
                'json' => [
                    'model' => $this->model,
                    'messages' => $messages
                ]
            ];

            $response = $this->client->post("v1/chat/completions", $requestData);

            if ($response->getStatusCode() !== 200) {
                throw new Exception("Error occurred during OpenAI API call: " . $response->getBody());
            }

            $responseData = json_decode($response->getBody(), true);
            return $responseData['choices'][0]['message']['content'] ?? '';
        }

        if ($this->provider === self::PROVIDER_GOOGLE) {
            $apiKey = env('GOOGLE_GEMINI_API_KEY');

            $contents = [];
            foreach ($messages as $message) {
                if ($message['role'] === 'user') {
                    $parts = [];
                    if (is_array($message['content'])) {
                        foreach ($message['content'] as $content) {
                            if ($content['type'] === 'text') {
                                $parts[] = ['text' => $content['text']];
                            } elseif ($content['type'] === 'image_url') {
                                $imageData = $content['image_url']['url'];
                                if (str_starts_with($imageData, 'data:image/')) {
                                    $parts[] = [
                                        'inline_data' => [
                                            'mime_type' => 'image/jpeg',
                                            'data' => base64_encode(file_get_contents($imageData))
                                        ]
                                    ];
                                }
                            }
                        }
                    } else {
                        $parts[] = ['text' => $message['content']];
                    }
                    $contents[] = ['parts' => $parts];
                }
            }

            $requestData = [
                'json' => [
                    'contents' => $contents
                ]
            ];

            $response = $this->client->post("v1beta/models/{$this->model}:generateContent?key={$apiKey}", $requestData);

            if ($response->getStatusCode() !== 200) {
                throw new Exception("Error occurred during Google API call: " . $response->getBody());
            }

            $responseData = json_decode($response->getBody(), true);
            return $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';
        }

        throw new Exception("Image analysis is only supported with OpenAI and Google providers");
    }

    /**
     * Generate an image using Google Gemini 2.5 Flash Image
     * @param string $prompt The image generation prompt
     * @return array ['image_data' => binary_data, 'mime_type' => string, 'filename' => string]
     * @throws Exception
     */
    public function generateImage(string $prompt): array
    {
        if ($this->provider !== self::PROVIDER_GOOGLE) {
            throw new Exception("Image generation is only supported with Google provider");
        }

        $requestStartTime = microtime(true);
        $promptSizeKb = round(strlen($prompt) / 1024, 2);
        $apiKey = env('GOOGLE_GEMINI_API_KEY');

        Log::info("Starting Google Gemini Image Generation API call", [
            'model' => self::MODEL_GEMINI_2_5_FLASH_IMAGE,
            'prompt_size_kb' => $promptSizeKb,
            'start_time' => date('Y-m-d H:i:s'),
            'has_api_key' => !empty($apiKey),
        ]);

        $requestData = [
            'json' => [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'responseModalities' => ['IMAGE', 'TEXT']
                ]
            ]
        ];

        try {
            $response = $this->client->post("v1beta/models/" . self::MODEL_GEMINI_2_5_FLASH_IMAGE . ":generateContent?key={$apiKey}", $requestData);
            $requestDuration = microtime(true) - $requestStartTime;

            Log::info("Google Gemini Image Generation API response received", [
                'status_code' => $response->getStatusCode(),
                'request_duration_seconds' => round($requestDuration, 2),
                'response_size_kb' => round(strlen($response->getBody()) / 1024, 2)
            ]);

            if ($response->getStatusCode() !== 200) {
                $errorBody = $response->getBody()->getContents();
                Log::error("Google Gemini Image Generation API error response", [
                    'status_code' => $response->getStatusCode(),
                    'error_body' => $errorBody
                ]);
                throw new Exception("Error occurred during Google Image Generation API call: " . $errorBody);
            }

            $responseData = json_decode($response->getBody(), true);

            // Extract image data from response
            $imageData = null;
            $mimeType = null;

            if (isset($responseData['candidates'][0]['content']['parts'])) {
                foreach ($responseData['candidates'][0]['content']['parts'] as $part) {
                    if (isset($part['inlineData']['data']) && isset($part['inlineData']['mimeType'])) {
                        $imageData = base64_decode($part['inlineData']['data']);
                        $mimeType = $part['inlineData']['mimeType'];
                        break;
                    }
                }
            }

            if (!$imageData) {
                throw new Exception('No image data found in Google Gemini Image Generation response');
            }

            // Generate filename with proper extension
            $extension = $this->getExtensionFromMimeType($mimeType);
            $filename = 'generated_' . time() . '_' . substr(md5($prompt), 0, 8) . $extension;

            Log::info("Google Gemini Image Generation API call completed successfully", [
                'image_size_kb' => round(strlen($imageData) / 1024, 2),
                'mime_type' => $mimeType,
                'filename' => $filename
            ]);

            return [
                'image_data' => $imageData,
                'mime_type' => $mimeType,
                'filename' => $filename
            ];

        } catch (GuzzleException $e) {
            $requestDuration = microtime(true) - $requestStartTime;

            Log::error("Google Gemini Image Generation API Guzzle exception", [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'request_duration_seconds' => round($requestDuration, 2),
                'prompt_size_kb' => $promptSizeKb
            ]);

            throw new Exception("Google Gemini Image Generation API request failed: " . $e->getMessage());
        }
    }

    /**
     * Get file extension from MIME type
     */
    private function getExtensionFromMimeType(string $mimeType): string
    {
        $extensions = [
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/gif' => '.gif',
            'image/webp' => '.webp',
            'image/bmp' => '.bmp',
            'image/svg+xml' => '.svg'
        ];

        return $extensions[$mimeType] ?? '.jpg';
    }


    protected function getCompletionWithWebSearch(string $prompt): string
    {
        Log::info('WebResearchService: Making web search request using Responses API', [
            'provider' => $this->provider,
            'model' => $this->model,
            'prompt_length' => strlen($prompt)
        ]);

        try {
            $response = $this->client->post("v1/responses", [
                'json' => [
                    'model' => $this->model,
                    'input' => $prompt,
                    'reasoning' => [
                        'effort' => 'medium'
                    ],
                    'tools' => [
                        [
                            'type' => 'web_search'
                        ]
                    ],
                    'tool_choice' => 'auto',
                    'include' => ['web_search_call.action.sources']
                ]
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new Exception("OpenAI Responses API returned status " . $response->getStatusCode());
            }

            $responseData = json_decode($response->getBody(), true);

            if (!isset($responseData['output'])) {
                throw new Exception("Invalid response format from OpenAI Responses API");
            }

            // Extract text content from the response output
            $content = '';
            foreach ($responseData['output'] as $outputItem) {
                if (isset($outputItem['type']) && $outputItem['type'] === 'message' &&
                    isset($outputItem['content']) && is_array($outputItem['content'])) {
                    foreach ($outputItem['content'] as $contentItem) {
                        if (isset($contentItem['type']) && $contentItem['type'] === 'output_text' &&
                            isset($contentItem['text'])) {
                            $content .= $contentItem['text'];
                        }
                    }
                }
            }

            if (empty($content)) {
                throw new Exception("No text content found in OpenAI Responses API response");
            }

            return $content;

        } catch (Exception $e) {
            Log::error('WebResearchService: Web search completion failed', [
                'error' => $e->getMessage(),
                'prompt_preview' => substr($prompt, 0, 200)
            ]);
            throw $e;
        }
    }
}