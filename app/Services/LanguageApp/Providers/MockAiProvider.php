<?php

namespace App\Services\LanguageApp\Providers;

use App\Services\LanguageApp\AiProvider;

final class MockAiProvider implements AiProvider
{
    public function __construct(private readonly array $cfg = [])
    {
    }

    public static function clip(string $s, int $max = 300): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '…' : $s;
    }

    public function generate(array $payload, array $opts = []): array
    {
        // минимальный валидный overview, который проходит JsonSchemaExamOverview
        $content = [
            'exam_name'        => $payload['exam_slug'] ?? 'mock_exam',
            'exam_description' => '',
            'timebox_minutes'  => 3,
            'sources' => [
                ['url' => 'https://example.com/spec', 'title' => 'Spec', 'publisher' => 'Example'],
            ],
            'archetypes' => [
                [
                    'id'     => 'reading_true_false',
                    'name'   => 'Reading — True/False',
                    'question_types' => ['True/False/Not Given'],
                    'typical_distractors' => ['Paraphrase traps'],
                    'verbs'  => ['select', 'decide'],
                    // допускаем оба формата весов — тут простой:
                    'weights' => ['Reading' => 1.0],
                    // numeric_ranges может быть объектом:
                    'numeric_ranges' => ['word_limits' => [1, 3]],
                    'difficulty' => 'medium',
                ],
            ],
            // опциональные поля можно не передавать
            'exam_matrix_provided' => false,
        ];

        $contentText = json_encode($content, JSON_UNESCAPED_UNICODE);
        $body = [
            'id'      => 'mock-resp-1',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role'    => 'assistant',
                        'content' => $contentText,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens'     => 10,
                'completion_tokens' => 50,
                'total_tokens'      => 60,
            ],
        ];
        $raw = json_encode($body, JSON_UNESCAPED_UNICODE);

        return [
            'ok'           => true,
            'raw'          => $raw,
            'body'         => $body,
            'content_text' => $contentText,
            'content'      => $content,
            'usage'        => $body['usage'],
        ];
    }
}
