<?php

return [
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
