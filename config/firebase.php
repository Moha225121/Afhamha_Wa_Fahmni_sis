<?php

return [
    'default' => 'app',
    'projects' => [
        'app' => [
            // Absolute path outside the checkout, or Application Default Credentials.
            'credentials' => env('FIREBASE_CREDENTIALS') ?: env('GOOGLE_APPLICATION_CREDENTIALS'),
            'cache_store' => 'file',
            'http_client_options' => ['timeout' => 5.0],
            // Never enable HTTP request/response logging: it can contain tokens.
            'logging' => ['http_log_channel' => null, 'http_debug_log_channel' => null],
        ],
    ],
];
