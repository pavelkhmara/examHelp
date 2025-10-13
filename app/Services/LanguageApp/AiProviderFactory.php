<?php

namespace App\Services\LanguageApp;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

final class AiProviderFactory
{
    public static function make(): callable
    {
        $cfg = config('api');
        if (($cfg['provider'] ?? null) !== 'gpt5mini') {
            throw new RuntimeException('Unsupported AI provider: ' . ($cfg['provider'] ?? 'null'));
        }

        $client = new Client([
            'base_uri' => rtrim($cfg['gpt5mini']['base_url'], '/').'/',
            'timeout'  => $cfg['timeout'],
        ]);

        $apiKey = $cfg['gpt5mini']['api_key'] ?? null;
        $model  = $cfg['model'] ?? 'gpt5-mini';

        if (!$apiKey) {
            throw new RuntimeException('GPT5_API_KEY is missing');
        }

        return function (array $messages, ?string $responseJsonSchema = null) use ($client, $apiKey, $model): array {
            if ($responseJsonSchema) {
                $messages[] = [
                    'role' => 'system',
                    'content' => 'Return ONLY valid JSON object. No comments, no explanations.',
                ];
            }

            // $headers = ['Authorization' => 'Bearer '.$GLOBALS['__ai_api_key'], 'Content-Type' => 'application/json'];

            $body = [
                'model'     => $model,
                'messages'  => $messages,
                'temperature' => 0,
            ];

            if ($responseJsonSchema) {
                $body['response_format'] = ['type' => 'json_object'];
                $messages[] = [
                    'role' => 'system',
                    'content' => 'Return ONLY valid JSON. No comments, no prose.'
                ];
            }

            if ($responseJsonSchema) {
                $body['response_format'] = ['type' => 'json_object'];
            }

            try {
                $res = $client->post('chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => $body,
                ]);
            } catch (GuzzleException $e) {
                throw new RuntimeException('AI HTTP error: ' . $e->getMessage());
            }

            $status = $res->getStatusCode();
            $raw    = (string) $res->getBody();

            if ($status < 200 || $status >= 300) {
                throw new RuntimeException('AI non-2xx status: ' . $status . ' body: ' . self::clip($raw));
            }

            $data = json_decode($raw, true);
            if ($data === null) {
                throw new RuntimeException('AI returned non-JSON body: ' . self::clip($raw) . ' (json error: ' . json_last_error_msg() . ')');
            }

            if (!isset($data['choices'][0]['message']['content'])) {
                throw new RuntimeException('AI response missing choices[0].message.content. Body: ' . self::clip($raw));
            }

            $content = $data['choices'][0]['message']['content'];
            $usage = $data['usage'] ?? ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

            if ($responseJsonSchema) {
                $json = json_decode($content, true);
                if (!is_array($json)) {
                    throw new RuntimeException('AI returned non-JSON content: ' . self::clip($content));
                }
                return ['ok' => true, 'data' => $json, 'usage' => $usage, 'raw' => $data];
            }

            return ['ok' => true, 'data' => ['text' => $content], 'usage' => $usage, 'raw' => $data];
        };
    }

    private static function clip(string $s, int $len = 600): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        return mb_strlen($s) > $len ? (mb_substr($s, 0, $len) . '…') : $s;
    }
}
