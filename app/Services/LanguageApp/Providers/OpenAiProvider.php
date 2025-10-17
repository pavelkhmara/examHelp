<?php

namespace App\Services\LanguageApp\Providers;

use App\Services\LanguageApp\AiProvider;
use Illuminate\Support\Facades\Log;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class OpenAiProvider implements AiProvider
{
    public function __construct(
        private readonly Client $http,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model
    ) {
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
        Log::debug('OpenAiProvider: generate start', ['payload' => $payload, 'options' => $opts ]);


        $cfg = config('ai');
        $openai_cfg = $cfg['openai'];
        $model = $this->model ?? $openai_cfg['model'];

        $baseMessages = $payload['messages'] ?? $payload['input'] ?? $payload;
        
        if (is_string($baseMessages)) {
            $baseMessages = [['role' => 'user', 'content' => $baseMessages]];
        }

        $messages = [];
        
        // 1. System message for JSON strict mode
        if ($openai_cfg['json_strict'] ?? false) {
            $messages[] = [
                'role' => 'system',
                'content' => 'Return ONLY valid JSON that matches the provided JSON schema. No prose, no markdown.'
            ];
        }

        // 2. Add baseMessages (user/assistant)
        $messages = array_merge($messages, $baseMessages);

        // 3. System message for JSON schema (if exist)
        $responseJsonSchema = $cfg['response_json_schema'] ?? null;
        if ($responseJsonSchema) {
            $messages[] = [
                'role' => 'system',
                'content' => 'Return JSON only matching the schema into response_json_schema. Your opinion important additions put into "additional_info" object.'
            ];
        }

        $body = [
            'model' => $model,
            'messages' => $messages,
        ];

        if ($openai_cfg['json_strict'] ?? false) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        if ($responseJsonSchema) {
            $body['response_format'] = $body['response_format'] ?? ['type' => 'json_object'];
            $body['response_format']['response_json_schema'] = $responseJsonSchema;
        }

        Log::debug('OpenAiProvider: final request body', ['body' => $body]);

        try {
            $res = $this->http->post('chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => $body,
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('AI HTTP error: ' . $e->getMessage());
            return ['ok'=>false,'data'=>null,'usage'=>[],'raw'=>['error'=>$e->getMessage()]];
        }

        $status = $res->getStatusCode();
        $raw    = (string) $res->getBody();

        Log::debug('AiProviderFactory: response status ►', [ 'status' => $status ]);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('AI non-2xx status: '.$status.' body: '.self::clip($raw));
        }

        $body = json_decode($raw, true) ?? [];
        if (!is_array($body) || !isset($body['choices'][0]['message']['content'])) {
            throw new \RuntimeException('AI response is malformed: '.self::clip($raw));
        }

        $contentText = $body['choices'][0]['message']['content'];
        $content    = json_decode($contentText, true);

        Log::debug('AiProviderFactory: response FULL ►', [ 'body' => $body, 'content' => $content, 'contentText' => $contentText ]);

        if (!is_array($content)) {
            throw new \RuntimeException('AI returned non-JSON content: '.self::clip($contentText));
        }

        return [
            'ok'               => true,
            'raw'              => $raw,                 // сырое тело HTTP-ответа провайдера
            'body'             => $body,                // декодированный top-level JSON провайдера
            'content_text'     => $contentText,         // строка JSON внутри message.content
            'content'          => $content,             // ДЕКОДИРОВАННЫЙ overview-объект — используем дальше в сервисе
            'usage'            => $body['usage'] ?? ['prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0],
        ];
    }

    private static function clip(string $s, int $len = 800): string
    {
        $s = trim($s);
        return mb_strlen($s) > $len ? (mb_substr($s, 0, $len).'…') : $s;
    }
}
