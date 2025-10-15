<?php

namespace App\Services\AI\Providers;

use App\Services\AI\AbstractAiService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class Gpt5MiniService extends AbstractAiService
{
    protected function endpoint(): string
    {
        $cfg = config('api.gpt5mini');
        $useResponses = (bool)($cfg['use_responses_api'] ?? false);
        $base = rtrim($cfg['base_url'], '/');
        return $useResponses
            ? $base . '/responses'
            : $base . '/chat/completions';
    }

    public function callAi(array $messages, array $opts = []): array
    {
        $cfg   = config('api');
        $prov  = $cfg['gpt5mini'];
        $model = $cfg['model'];

        $payload = $this->buildPayload($messages, $opts, $model, (bool)$prov['use_responses_api']);

        $resp = Http::withHeaders([
                'Authorization' => 'Bearer '.$prov['api_key'],
                'Content-Type'  => 'application/json',
            ])
            ->timeout((int)($cfg['timeout'] ?? 60))
            ->post($this->endpoint(), $payload);

        if (!$resp->ok()) {
            // Проброс понятной ошибки + сохранение
            $body = $resp->json();
            $msg  = Arr::get($body, 'error.message', 'AI provider error');
            // лог/метрики можно тут
            throw new \RuntimeException($msg, $resp->status());
        }

        $data = $resp->json();

        // Нормализуем ответ в единый вид
        return $this->normalizeResponse($data, (bool)$prov['use_responses_api']);
    }

    protected function buildPayload(array $messages, array $opts, string $model, bool $useResponses): array
    {
        $temperature = $opts['temperature'] ?? 0.2;
        $web         = (bool)($opts['web_search'] ?? false);
        $strictJson  = (bool)(config('api.json_strict') ?? true);
        $jsonSchema  = $opts['json_schema'] ?? null;

        // инструменты
        $tools = [];
        if ($web) {
            // функция web_search (модель вернёт tool_call, мы обработаем)
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'web_search',
                    'description' => 'Search the web, return brief synthesis with sources',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string'],
                            'max_results' => ['type' => 'integer', 'default' => 5]
                        ],
                        'required' => ['query']
                    ],
                ],
            ];
        }

        // response_format (строгий JSON)
        $responseFormat = null;
        if ($strictJson && $jsonSchema) {
            $responseFormat = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $jsonSchema['name'] ?? 'result',
                    'schema' => $jsonSchema['schema'] ?? ['type' => 'object']
                ]
            ];
        } elseif ($strictJson) {
            $responseFormat = ['type' => 'json_object'];
        }

        if ($useResponses) {
            // payload для /responses
            $input = [
                'role' => 'user',
                'content' => $this->convertMessagesToResponsesBlocks($messages),
            ];

            $payload = [
                'model' => $model,
                'input' => $input,
                'temperature' => $temperature,
            ];

            if (!empty($tools)) {
                $payload['tools'] = $tools;
            }
            if ($responseFormat) {
                $payload['response_format'] = $responseFormat;
            }

            return $payload;
        }

        // payload для /chat/completions
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
        ];
        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }
        if ($responseFormat) {
            $payload['response_format'] = $responseFormat;
        }

        return $payload;
    }

    protected function normalizeResponse(array $data, bool $useResponses): array
    {
        if ($useResponses) {
            // В /responses первое output_text (или tool calls)
            $outputs = $data['output'] ?? [];
            $text = '';
            $toolCalls = [];
            foreach ($outputs as $out) {
                if (($out['type'] ?? '') === 'message') {
                    foreach ($out['content'] ?? [] as $c) {
                        if (($c['type'] ?? '') === 'output_text') {
                            $text .= $c['text'] ?? '';
                        } elseif (($c['type'] ?? '') === 'tool_call') {
                            $toolCalls[] = $c;
                        }
                    }
                }
            }
            return [
                'text' => $text,
                'tool_calls' => $toolCalls,
                'raw' => $data,
            ];
        }

        // /chat/completions
        $choice = $data['choices'][0] ?? [];
        $msg    = $choice['message'] ?? [];
        return [
            'text' => (string)($msg['content'] ?? ''),
            'tool_calls' => $msg['tool_calls'] ?? [],
            'raw' => $data,
            'usage' => $data['usage'] ?? null,
        ];
    }

    protected function convertMessagesToResponsesBlocks(array $messages): array
    {
        // делаем простой конверт: system/user/assistant -> content:text
        $blocks = [];
        foreach ($messages as $m) {
            $role = $m['role'] ?? 'user';
            $content = $m['content'] ?? '';
            $blocks[] = [
                'type' => 'text',
                'text' => "[{$role}] ".$content,
            ];
        }
        return $blocks;
    }
}
