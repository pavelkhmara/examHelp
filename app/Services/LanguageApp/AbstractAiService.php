<?php

namespace App\Services\LanguageApp;

use App\Models\GenerationTask;
use Carbon\Carbon;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

abstract class AbstractAiService
{
    protected ?AsyncAiProvider $asyncAi = null;

    public function __construct(
        protected readonly AiProvider $ai
    ) {
        // Initialize async provider if async is enabled
        if (config('ai.async_enabled', false)) {
            try {
                $this->asyncAi = AiProviderFactory::makeAsync(
                    config('ai.provider'),
                    config('ai')
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to initialize async AI provider, async disabled', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * @param  array  $opts  ['schema' => array|null, 'web' => bool, 'files' => array<int,\SplFileInfo|string>, 'model' => string|null, 'prompt_class' => string|null, 'prompt_args' => array|null]
     */
    protected function callAi(array $payload, array $opts = []): array
    {
        $modelAlias = $opts['model'] ?? null;
        Log::debug('AbstractAiService: calling AI', ['$payload' => $payload, 'options' => $opts, 'model_requested' => $modelAlias]);

        $cfg = config('ai');
        $provider = $cfg['provider'];
        $contextNotes = '';

        // 1) Context
        $examTitle = $payload['exam_title'] ?? $payload['exam_slug'] ?? 'No exam title provided';
        $userInput = $payload['input'];
        // web
        if (! empty($opts['web']) && $cfg[strval($provider)]['enable_web_search']) {
            $contextNotes = $this->gatherWebHints([$payload['exam_level'], $payload['exam_description'] ?? $payload['input'] ?? 'No exam info provided'], (int) ($cfg[strval($provider)]['max_web_snippets'] ?? 5));
        }

        // files
        $filesHint = '';
        if (! empty($opts['files'])) {
            $filesHint = $this->gatherFileTexts($opts['files']);
            $payload['files_hint'] = $filesHint;
        }

        // 2) Build prompt using specified prompt class or default PromptExamOverview
        $retryHint = $payload['retry_hint'] ?? null;
        $userInputParsed = $payload['user_input'] ?? null;
        $promptClass = $opts['prompt_class'] ?? Prompts\PromptExamOverview::class;
        $promptArgs = $opts['prompt_args'] ?? [];

        // Get confirmed identity from payload (if provided)
        $confirmedIdentity = $payload['confirmed_identity'] ?? null;

        // Build prompt with provided args or default args
        $defaultArgs = [
            $examTitle,
            $userInput,
            $contextNotes,
            $retryHint,
            $userInputParsed,
            $filesHint,
            $confirmedIdentity,
        ];

        $buildArgs = ! empty($promptArgs) ? $promptArgs : $defaultArgs;
        $prompt = $promptClass::build(...$buildArgs);

        // 3) Messages
        $messages = [
            [
                'role' => 'system',
                'content' => $prompt,
            ],
        ];

        if (! empty($payload['user_input'])) {
            $messages[] = [
                'role' => 'user',
                'content' => $payload['user_input'],
            ];
        }

        $payload['messages'] = $messages;

        Log::debug('AbstractAiService: prepared payload for AI', ['messages' => $messages]);

        $res = $this->ai->generate($payload, $opts);

        Log::debug('AbstractAiService.callAi', [
            'ok' => $res['ok'] ?? null,
            'usage' => $res['usage'] ?? null,
            'model' => $res['model'] ?? 'unknown',
            'model_alias' => $res['model_alias'] ?? null,
        ]);
        // $this->writeJsonToFile($payload['exam_slug'], $payload['exam_level'], $res);

        // Add sent messages to result for logging
        $res['sent_messages'] = $messages;

        return $res;
    }

    private function gatherWebHints($exam_info, int $limit = 5): string
    {
        // СТАБ: здесь может быть ваш сервис web-поиска (SerpAPI, proxy и т.д.)
        // Пока просто возвращаем пустышку, чтобы не ломать протокол
        return implode(', ', $exam_info);
    }

    private function gatherFileTexts(array $files): string
    {
        if (empty($files)) {
            return '';
        }

        $hints = [];

        foreach ($files as $f) {
            // массив (наш формат из buildFilesForExam) — используем текст из extracted_text
            if (is_array($f)) {
                $name = (string) ($f['name'] ?? $f['filename'] ?? $f['id'] ?? 'document');
                $text = (string) ($f['text'] ?? '');

                if ($text !== '') {
                    $hints[] = "=== DOCUMENT: {$name} ===\n{$text}\n=== END DOCUMENT ===";
                } else {
                    // Если текста нет, хотя бы имя укажем
                    $hints[] = "DOCUMENT (no text extracted): {$name}";
                }

                continue;
            }

            // строка пути — берём basename (оставляем для совместимости)
            if (is_string($f)) {
                $hints[] = 'FILE: '.basename($f);

                continue;
            }

            // объекты: UploadedFile и пр.
            if (is_object($f)) {
                $name = '[unknown]';
                if (method_exists($f, 'getClientOriginalName')) {
                    $name = $f->getClientOriginalName();
                } elseif (method_exists($f, 'getFilename')) {
                    $name = $f->getFilename();
                } elseif (method_exists($f, '__toString')) {
                    $name = basename((string) $f);
                }

                $hints[] = "FILE: {$name}";
            }
        }

        if (empty($hints)) {
            return '';
        }

        return "\n\n## UPLOADED EXAM DOCUMENTS ##\n\n".implode("\n\n", $hints)."\n\n## END DOCUMENTS ##\n";
    }

    /**
     * Call AI asynchronously (returns promise, non-blocking)
     *
     * @return PromiseInterface Promise that resolves to AI response array
     */
    protected function callAiAsync(array $payload, array $opts = []): PromiseInterface
    {
        if (! $this->asyncAi) {
            throw new \RuntimeException('Async AI provider not initialized. Enable AI_ASYNC_ENABLED=true');
        }

        $modelAlias = $opts['model'] ?? null;
        Log::debug('AbstractAiService: calling AI async', ['model_requested' => $modelAlias]);

        // Prepare payload (same as callAi)
        $cfg = config('ai');
        $provider = $cfg['provider'];
        $contextNotes = '';

        $examTitle = $payload['exam_title'] ?? $payload['exam_slug'] ?? 'No exam title provided';
        $userInput = $payload['input'];

        // Web hints (if enabled)
        if (! empty($opts['web']) && $cfg[strval($provider)]['enable_web_search']) {
            $contextNotes = $this->gatherWebHints([$payload['exam_level'], $payload['exam_description'] ?? $payload['input'] ?? 'No exam info provided'], (int) ($cfg[strval($provider)]['max_web_snippets'] ?? 5));
        }

        // File hints
        $filesHint = '';
        if (! empty($opts['files'])) {
            $filesHint = $this->gatherFileTexts($opts['files']);
            $payload['files_hint'] = $filesHint;
        }

        // Build prompt
        $retryHint = $payload['retry_hint'] ?? null;
        $userInputParsed = $payload['user_input'] ?? null;
        $prompt = Prompts\PromptExamOverview::build(
            $examTitle,
            $userInput,
            $contextNotes,
            $retryHint,
            $userInputParsed,
            $filesHint
        );

        $messages = [
            ['role' => 'system', 'content' => $prompt],
        ];

        if (! empty($payload['user_input'])) {
            $messages[] = ['role' => 'user', 'content' => $payload['user_input']];
        }

        $payload['messages'] = $messages;

        Log::debug('AbstractAiService: sending async AI request', ['messages_count' => count($messages)]);

        return $this->asyncAi->generateAsync($payload, $opts);
    }

    /**
     * Call multiple AI requests in parallel
     *
     * @param  array  $calls  Array of ['key' => string, 'payload' => array, 'opts' => array]
     * @return array Keyed array of AI responses
     */
    protected function callAiParallel(array $calls): array
    {
        if (! $this->asyncAi) {
            throw new \RuntimeException('Async AI provider not initialized. Enable AI_ASYNC_ENABLED=true');
        }

        Log::debug('AbstractAiService: calling AI parallel', ['count' => count($calls)]);

        // Build promises for each call
        $promises = [];
        foreach ($calls as $call) {
            $key = $call['key'];
            $payload = $call['payload'];
            $opts = $call['opts'] ?? [];

            $promises[$key] = $this->callAiAsync($payload, $opts);
        }

        // Wait for all promises to resolve
        $results = Utils::unwrap($promises);

        Log::debug('AbstractAiService: parallel calls completed', ['count' => count($results)]);

        return $results;
    }

    protected function log(GenerationTask $task, string $stage, array $request, array $response): void
    {
        // Use sent_messages from response if available (full prompt), otherwise use request array
        $loggedRequest = $response['sent_messages'] ?? $request;

        // Save full response for async provider (includes content_text), or legacy format
        $loggedResponse = $response['data'] ?? $response['json'] ?? $response;

        \App\Models\GenerationLog::create([
            'exam_id' => $task->exam_id,
            'generation_task_id' => $task->id,
            'stage' => $stage,
            'model' => $response['model'] ?? null,
            'model_alias' => $response['model_alias'] ?? null,
            'request' => $loggedRequest,
            'response' => $loggedResponse,
            'prompt_tokens' => $response['usage']['prompt_tokens'] ?? 0,
            'completion_tokens' => $response['usage']['completion_tokens'] ?? 0,
            'total_tokens' => $response['usage']['total_tokens'] ?? 0,
        ]);
    }

    protected function writeJsonToFile(string $examSlug, string $examLevel, mixed $res1): void
    {
        try {
            // 1) Готовим имя файла
            $slugRaw = $exam_slug ?? ($exam['slug'] ?? 'exam');   // подстрой под свой контекст
            $levelRaw = $exam_level ?? ($exam['level'] ?? 'level'); // подстрой под свой контекст

            $slug = Str::slug((string) $slugRaw, '_');
            $level = Str::slug((string) $levelRaw, '_');

            $timestamp = Carbon::now()->format('Ymd_His');
            $fileName = "resp_{$slug}_{$level}_{$timestamp}.json";

            // 2) Папка root/files от корня проекта
            $dir = base_path('files');
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            // 3) Данные в нужном порядке (buckets → overview_normalized → res1['content'])
            $payloadOrdered = [
                'content' => $res1['content'] ?? null,
            ];

            // 4) Сохраняем JSON
            $json = json_encode(
                $payloadOrdered,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );

            $fullPath = $dir.DIRECTORY_SEPARATOR.$fileName;
            file_put_contents($fullPath, $json);

            // (опционально) залогировать успех
            Log::info('Exam research saved', ['path' => $fullPath]);
        } catch (\Throwable $e) {
            // (опционально) залогировать ошибку
            Log::error('Failed to save exam research JSON', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
