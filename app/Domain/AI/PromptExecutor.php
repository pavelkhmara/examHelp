<?php

namespace App\Domain\AI;

use App\Domain\AI\Contracts\Prompt;
use App\Models\GenerationLog;
use App\Models\GenerationTask;
use App\Services\LanguageApp\AiProvider;
use App\Services\LanguageApp\AiProviderFactory;
use App\Services\LanguageApp\Validators\AiJsonValidator;
use App\Support\LiteValidationException;
use Illuminate\Support\Facades\Log;

final class PromptExecutor
{
    public function __construct(
        private readonly ?AiProvider $ai = null
    ) {}

    /**
     * Execute prompt with retries & metrics. Writes GenerationLog per attempt.
     * Does NOT change task status here (это делает job-сервис).
     *
     * @return array{data:array, usage:array}
     *
     * @throws \RuntimeException|LiteValidationException
     */
    public function run(GenerationTask $task, Prompt $prompt): array
    {
        $cfg = config('ai');
        $providerName = $cfg['provider'] ?? 'mock';
        $ai = $this->ai ?: AiProviderFactory::make($providerName, $cfg);

        $maxRetries = (int) ($cfg['retries'] ?? 2);
        $baseBackoffMs = (int) ($cfg['backoff_ms'] ?? 500);

        $attempt = 0;
        $error = null;

        while ($attempt <= $maxRetries) {
            $attempt++;
            try {
                // increment attempts on task
                $task->increment('attempts');

                $payload = [
                    'system' => $prompt->system(),
                    'user' => $prompt->user(),
                ];

                $opts = array_merge($prompt->opts(), [
                    'json_schema' => $prompt->jsonSchema(),
                ]);

                $result = $ai->generate($payload, $opts);
                $usage = (array) ($result['usage'] ?? []);

                // log raw
                GenerationLog::create([
                    'generation_task_id' => $task->id,
                    'stage' => $prompt->id(),
                    'request' => ['messages' => $payload, 'opts' => $opts],
                    'response' => $result,
                    'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                    'completion_tokens' => $usage['completion_tokens'] ?? null,
                    'total_tokens' => $usage['total_tokens'] ?? null,
                ]);

                if (empty($result['ok'])) {
                    throw new \RuntimeException($result['error'] ?? 'AI error');
                }

                $data = (array) ($result['data'] ?? []);
                // strict validate
                AiJsonValidator::validate($prompt->jsonSchema(), $data);

                // success
                $task->refresh(); // ensure attempts visible in UI

                return ['data' => $data, 'usage' => $usage];
            } catch (\Throwable $e) {
                $error = $e;
                Log::warning('PromptExecutor attempt failed', [
                    'task_id' => $task->id,
                    'stage' => $prompt->id(),
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt > $maxRetries) {
                    break;
                }

                // backoff
                usleep(($baseBackoffMs * (2 ** ($attempt - 1))) * 1000);
            }
        }

        throw (is_object($error) ? $error : new \RuntimeException('AI failed after retries'));
    }
}
