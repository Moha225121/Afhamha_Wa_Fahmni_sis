<?php

return [
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY', ''),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
        'timeout_seconds' => 60,
        'max_output_tokens' => 4096,
    ],

    'input' => [
        'min_characters' => 2,
        'max_characters' => 4000,
    ],

    'reply' => [
        'max_characters' => 12000,
    ],

    'history' => [
        'max_messages' => 20,
        'max_characters' => 16000,
    ],

    'idempotency' => [
        'pending_timeout_seconds' => 120,
    ],

    'display' => [
        'conversations_per_page' => 30,
        'messages_per_page' => 50,
        'sidebar_conversations' => 29,
    ],

    'rate_limits' => [
        'conversations_per_minute' => 10,
        'messages_per_minute' => 20,
    ],
];
