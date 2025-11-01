<?php

namespace App\Services\LanguageApp;

use App\Models\GenerationTask;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

abstract class AbstractAiService
{
    public function __construct(
        protected readonly AiProvider $ai
    ) {}

    /**
     * @param  array  $opts  ['schema' => array|null, 'web' => bool, 'files' => array<int,\SplFileInfo|string>, 'model' => string|null]
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

        // 2) Build prompt using PromptExamOverview
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

    protected function log(GenerationTask $task, string $stage, array $request, array $response): void
    {
        \App\Models\GenerationLog::create([
            'exam_id' => $task->exam_id,
            'generation_task_id' => $task->id,
            'stage' => $stage,
            'model' => $response['model'] ?? null,
            'model_alias' => $response['model_alias'] ?? null,
            'request' => $request,
            'response' => $response['data'] ?? $response['json'] ?? $response['content'] ?? null,
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
