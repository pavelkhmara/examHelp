<?php

return [
    // openai | gateway | mock
    'provider' => env('AI_PROVIDER', 'mock'),

    'openai' => [
        'base_url' => rtrim(env('AI_BASE_URL', 'https://api.openai.com/v1'), '/'),
        'api_key' => env('AI_API_KEY'),
        'model' => env('AI_MODEL', 'gpt-4o-mini'),
        'timeout' => (int) env('AI_TIMEOUT', 180),

        'json_strict' => (bool) env('AI_JSON_STRICT', true),
        'enable_mock' => (bool) env('AI_ENABLE_MOCK', false),
        'fallback_to_mock_on_error' => (bool) env('AI_FALLBACK_TO_MOCK_ON_ERROR', true),

        'enable_web_search' => (bool) env('AI_ENABLE_WEB_SEARCH', true),
        'max_web_snippets' => (int) env('AI_WEB_SNIPPETS', 5),

        'defaults' => [
        ],
    ],

    'mock' => [
        'base_url' => '/',
        'api_key' => 'api_key',
        'model' => 'mock_model',
        'timeout' => 60,

        'json_strict' => true,
        'enable_mock' => true,
        'fallback_to_mock_on_error' => true,

        'enable_web_search' => false,
        'max_web_snippets' => 0,

        'exam_info' => [
            'title' => 'Mocked Exam',
            'description' => 'This is a mocked response.',
            'language' => 'en',
        ],
    ],

    'documents' => [
        // сколько документов максимум даём в files_hint
        'max_docs_hint' => env('AI_DOCS_MAX_HINT', 3),
        // сколько символов берём из каждого документа (усекаем большой текст)
        'max_chars_per_doc' => env('AI_DOCS_MAX_CHARS', 4000),
        // «вес» документа относительно web-источников (подсказка в промпт)
        'weight' => env('AI_DOCS_PREFERRED_WEIGHT', 2.0),
        // если true — в промпте явно просим предпочесть документы
        'prefer_documents' => env('AI_DOCS_PREFER', true),
    ],

    'evaluation' => [
        // включить LLM-подсказки в фидбек (пока по умолчанию выкл — Stage 1)
        'enable_llm' => env('AI_EVALUATION_LLM', false),
    ],

    // Schema has been moved to App\Services\LanguageApp\Prompts\PromptExamOverview::getSchemaArray()
];
