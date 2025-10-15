<?php

return [
    'provider' => env('AI_PROVIDER', 'gpt5mini'),
    'model' => env('AI_MODEL', 'gpt-5-mini'),
    'timeout' => (int) env('AI_REQUEST_TIMEOUT', 60),
    'json_strict' => (bool) env('AI_JSON_STRICT', true),

    'gpt5mini' => [
        'base_url' => env('AI_BASE_URL', 'https://api.openai.com/v1'),
        'api_key'  => env('AI_API_KEY'),
        'use_responses_api' => env('AI_USE_RESPONSES', false),
    ],
];
