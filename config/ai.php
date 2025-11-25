<?php

return [
    // openai | gateway | mock
    'provider' => env('AI_PROVIDER', 'mock'),

    // Async processing configuration
    'async_enabled' => (bool) env('AI_ASYNC_ENABLED', false),
    'parallel_max' => (int) env('AI_PARALLEL_MAX', 5), // Max parallel AI requests

    // Task-level parallelization (NEW)
    // When enabled, each filter/slot gets its own job for parallel execution across workers
    // Expected speedup: 3-4x (20-30min → 4-6min for 40 questions)
    'use_task_level_parallelization' => (bool) env('AI_USE_TASK_LEVEL_PARALLELIZATION', false),

    // Rate limiting configuration
    'rate_limit_enabled' => (bool) env('AI_RATE_LIMIT_ENABLED', true),
    'rate_limit_rpm' => (int) env('AI_RATE_LIMIT_RPM', 60), // Requests per minute
    'rate_limit_retries' => (int) env('AI_RATE_LIMIT_RETRIES', 3),
    'rate_limit_retry_delay_ms' => (int) env('AI_RATE_LIMIT_RETRY_DELAY_MS', 1000),

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

    // Model aliases and specific use cases
    'models' => [
        'default' => env('AI_MODEL', 'gpt-5-mini'),
        'thinking' => env('AI_MODEL_THINKING', 'gpt-5'), // GPT-5 for complex reasoning tasks (overview)
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

    // File Attachments (OpenAI Files API) - alternative to text extraction
    'file_attachments' => [
        // Enable file attachments instead of text extraction
        'enabled' => (bool) env('AI_FILE_ATTACHMENTS_ENABLED', false),
        // Automatically upload documents to OpenAI on extraction complete
        'auto_upload' => (bool) env('AI_FILE_ATTACHMENTS_AUTO_UPLOAD', true),
        // Delete files from OpenAI when document deleted
        'auto_cleanup' => (bool) env('AI_FILE_ATTACHMENTS_CLEANUP', true),
        // Supported MIME types for file attachments
        'supported_mimes' => ['application/pdf', 'image/png', 'image/jpeg', 'image/jpg'],
        // Max file size for attachments (20MB OpenAI limit)
        'max_size_mb' => (int) env('AI_FILE_ATTACHMENTS_MAX_MB', 20),
    ],

    'evaluation' => [
        // включить LLM-подсказки в фидбек (пока по умолчанию выкл — Stage 1)
        'enable_llm' => env('AI_EVALUATION_LLM', false),
    ],

    // Text-to-Speech configuration (OpenAI TTS)
    'tts' => [
        'enabled' => (bool) env('AI_TTS_ENABLED', false),
        'api_key' => env('AI_TTS_API_KEY', env('AI_API_KEY')), // Use main API key if not specified
        'base_url' => env('AI_TTS_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('AI_TTS_MODEL', 'tts-1'), // tts-1 or tts-1-hd
        'voice' => env('AI_TTS_VOICE', 'alloy'), // alloy, echo, fable, onyx, nova, shimmer
        'speed' => (float) env('AI_TTS_SPEED', 1.0), // 0.25 to 4.0
        'timeout' => (int) env('AI_TTS_TIMEOUT', 60),
    ],

    // Image Search and Selection configuration
    'images' => [
        'enabled' => (bool) env('AI_IMAGE_ENABLED', false),
        'test_mode' => (bool) env('AI_IMAGE_TEST_MODE', true), // Return top-3 instead of top-1
        'provider' => env('AI_IMAGE_PROVIDER', 'unsplash'), // unsplash, pexels, pixabay
        'search_count' => (int) env('AI_IMAGE_SEARCH_COUNT', 15), // How many images to search
        'timeout' => (int) env('AI_IMAGE_TIMEOUT', 30),

        // Unsplash API configuration
        'unsplash' => [
            'api_key' => env('AI_IMAGE_UNSPLASH_KEY'),
            'base_url' => 'https://api.unsplash.com',
        ],

        // Pexels API configuration
        'pexels' => [
            'api_key' => env('AI_IMAGE_PEXELS_KEY'),
            'base_url' => 'https://api.pexels.com/v1',
        ],

        // Pixabay API configuration
        'pixabay' => [
            'api_key' => env('AI_IMAGE_PIXABAY_KEY'),
            'base_url' => 'https://pixabay.com/api',
        ],

        // AI selection model (gpt-5-mini for image evaluation)
        'selection_model' => env('AI_IMAGE_SELECTION_MODEL', 'gpt-5-mini'),
    ],

    // Schema has been moved to App\Services\LanguageApp\Prompts\PromptExamOverview::getSchemaArray()
];
