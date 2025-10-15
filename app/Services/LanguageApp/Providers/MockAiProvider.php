<?php

namespace App\Services\LanguageApp\Providers;

use App\Services\LanguageApp\AiProvider;

final class MockAiProvider implements AiProvider
{
    public function __construct(private readonly array $fixture = [])
    {
    }

    public function generate(array $payload, array $opts = []): array
    {
        // Здесь можно подмешивать schema/files/web или возвращать фиксированные ответы.
        $data = $this->fixture ?: [
            'exam_info' => [
                'title'       => 'Mocked Exam',
                'description' => 'This is a mocked response.',
                'language'    => 'en',
            ],
        ];

        return [
            'ok'    => true,
            'data'  => $data,
            'usage' => ['prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0],
            'raw'   => $data,
        ];
    }
}
