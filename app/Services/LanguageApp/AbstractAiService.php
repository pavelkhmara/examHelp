<?php

namespace App\Services\LanguageApp;

use Illuminate\Support\Facades\Log;
use App\Models\GenerationLog;
use App\Models\GenerationTask;

abstract class AbstractAiService
{
    public function __construct(
        protected readonly AiProvider $ai
    ) {}

    /**
     * @param array $payload
     * @param array $opts       ['schema' => array|null, 'web' => bool, 'files' => array<int,\SplFileInfo|string>]
     */
    protected function callAi(array $payload, array $opts = []): array
    {
        Log::debug('AbstractAiService: calling AI', ['$payload' => $payload, 'options' => $opts]);

        $cfg = config('ai');
        $provider = $cfg['provider'];
        $contextNotes = '';
 
        // 1) Context
        $examInfo = $payload['exam_slug'] ?? $payload['input'] ?? 'No exam info provided';
        // web
        if (!empty($opts['web']) && $cfg[strval($provider)]['enable_web_search']) {
            $contextNotes = $this->gatherWebHints($examInfo, (int)($cfg[strval($provider)]['max_web_snippets'] ?? 5));
        }
    
        // files
        if (!empty($opts['files'])) {
            $payload['files_hint'] = $this->gatherFileTexts($opts['files']);
        }
    
        // 2) Prompt
        $prompt = <<<EOT
You are an educational researcher for exam prep.
Information from user about exam: {$contextNotes}

You must browse the web to discover authentic question patterns for the target exam.
Follow these constraints:
- Use at least 4 reputable sources with diversity (.gov, .edu, official exam sites, major publishers).
- Extract patterns (archetypes), typical distractors, verbs, numeric ranges, units, common visuals, difficulty bands.
- Add per-category weights by mapping archetypes to categories from the provided exam_matrix.
- Record each source: url, title, publisher
- If evidence conflicts, include both views and explain under rationale.

Output strictly the JSON object described in the response_json_schema. If unsure, be conservative.

Task: Mine question archetypes and style for the exam.

exam_name: {$examInfo}
exam_description: {{EXAM_VARIANT}}
timebox_minutes: 3

exam_matrix_json:
{{EXAM_MATRIX_JSON}}
EOT;
    

        // 3) Messages
        $messages = [
            [
                'role'    => 'system',
                'content' => $prompt,
            ]
        ];

        if (!empty($payload['user_input'])) {
            $messages[] = [
                'role'    => 'user',
                'content' => $payload['user_input']
            ];
        }
    
        $payload['messages'] = $messages;

        Log::debug('AbstractAiService: prepared payload for AI', ['messages' => $messages]);
        
        $res = $this->ai->generate($payload, $opts);
        
        Log::debug('AbstractAiService.callAi', [
            'ok' => $res['ok'] ?? null,
            'usage' => $res['usage'] ?? null,
        ]);
        
        return $res;
    }
    
    private function gatherWebHints($exam_info, int $limit = 5): string
    {
        // СТАБ: здесь может быть ваш сервис web-поиска (SerpAPI, proxy и т.д.)
        // Пока просто возвращаем пустышку, чтобы не ломать протокол
        return $exam_info;
    }
    
    private function gatherFileTexts(array $files): string
    {
        // СТАБ: здесь подключите ваш DocumentIngestService (pdf/docx/jpg → OCR/текст)
        // Пока листаем имена файлов, чтобы AI видел подсказку
        $names = array_map(function($f){
            return is_string($f) ? basename($f) : (method_exists($f, 'getFilename') ? $f->getFilename() : '[unknown]');
        }, $files);
        return 'FILES_HINTS: ' . implode(', ', $names);
    }
    
    protected function log(GenerationTask $task, string $stage, array $request, array $response): void
    {
        \App\Models\GenerationLog::create([
            'generation_task_id' => $task->id,
            'stage'              => $stage,
            'request'            => $request,
            'response'           => $response['data'] ?? null,
            'prompt_tokens'      => $response['usage']['prompt_tokens'] ?? 0,
            'completion_tokens'  => $response['usage']['completion_tokens'] ?? 0,
            'total_tokens'       => $response['usage']['total_tokens'] ?? 0,
        ]);
    }
}
