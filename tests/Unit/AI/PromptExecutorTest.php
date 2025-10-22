<?php

namespace Tests\Unit\Ai;

use App\Domain\AI\PromptExecutor;
use App\Domain\AI\Contracts\Prompt;
use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromptExecutorTest extends TestCase
{
    use RefreshDatabase;

    public function test_executor_retries_and_logs_usage_and_validates(): void
    {
        $exam = Exam::factory()->create();
        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type'    => 'research',
            'status'  => 'running',
            'request' => ['notes' => 'Chcę zdać egzamin B2'],
        ]);

        // Fake Prompt
        $prompt = new class implements Prompt {
            public function id(): string { return 'research_overview'; }
            public function system(): string { return 'sys'; }
            public function user(): string { return 'user'; }
            public function jsonSchema(): array {
                return [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['tasks','sources','summary'],
                    'properties' => [
                        'summary' => ['type' => 'string'],
                        'tasks' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'object',
                                'required' => ['type','name','rationale','expected_payload'],
                                'properties' => [
                                    'type' => ['type' => 'string', 'enum' => ['SINGLE_SELECT']],
                                    'name' => ['type' => 'string'],
                                    'rationale' => ['type' => 'string'],
                                    'expected_payload' => ['type' => 'object'],
                                ],
                                'additionalProperties' => false,
                            ],
                        ],
                        'sources' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'object',
                                'required' => ['url','title'],
                                'properties' => [
                                    'url' => ['type' => 'string'],
                                    'title' => ['type' => 'string'],
                                ],
                                'additionalProperties' => false,
                            ]
                        ],
                    ],
                ];
            }
            public function opts(): array { return ['temperature'=>0.2,'web_search'=>true]; }
        };

        // Fake Provider: 1-я попытка ошибка, 2-я — успех
        $fake = new class implements AiProvider {
            private int $calls = 0;
            public function generate(array $payload, array $opts = []): array
            {
                $this->calls++;
                if ($this->calls === 1) {
                    return ['ok' => false, 'error' => 'temporary', 'usage' => ['prompt_tokens'=>10,'completion_tokens'=>0,'total_tokens'=>10]];
                }
                return [
                    'ok' => true,
                    'data' => [
                        'summary' => 'ok',
                        'tasks' => [[
                            'type' => 'SINGLE_SELECT',
                            'name' => 'Task 1',
                            'rationale' => 'why',
                            'expected_payload' => ['min_options'=>3],
                        ]],
                        'sources' => [[ 'url'=>'https://example.com','title'=>'Ex' ]],
                    ],
                    'usage' => ['prompt_tokens'=>42,'completion_tokens'=>99,'total_tokens'=>141],
                ];
            }
        };

        config()->set('ai.retries', 2);
        config()->set('ai.backoff_ms', 1);

        $res = (new PromptExecutor($fake))->run($task, $prompt);

        $this->assertEquals(141, $res['usage']['total_tokens']);
        $this->assertDatabaseHas('generation_tasks', ['id' => $task->id, 'attempts' => 2]);
        $this->assertDatabaseCount('generation_logs', 2);
    }
}
