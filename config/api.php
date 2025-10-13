<?php

return [
    'provider' => env('AI_PROVIDER', 'gpt5mini'),
    'model' => env('AI_MODEL', 'gpt5-mini'),
    'timeout' => (int) env('AI_REQUEST_TIMEOUT', 60),
    'json_strict' => (bool) env('AI_JSON_STRICT', true),

    'gpt5mini' => [
        'base_url' => env('GPT5_BASE_URL', 'https://api.openai.com/v1'),
        'api_key'  => env('GPT5_API_KEY'),
    ],
];
