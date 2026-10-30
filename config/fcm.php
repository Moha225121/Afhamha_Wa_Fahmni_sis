<?php

return [
    'enabled' => (bool) env('FCM_ENABLED', false),
    // Only this allowlist is sent to browsers. Admin credentials never belong here.
    'web' => [
        'apiKey' => env('FIREBASE_WEB_API_KEY'),
        'authDomain' => env('FIREBASE_WEB_AUTH_DOMAIN'),
        'projectId' => env('FIREBASE_PROJECT_ID'),
        'messagingSenderId' => env('FIREBASE_MESSAGING_SENDER_ID'),
        'appId' => env('FIREBASE_WEB_APP_ID'),
    ],
    'vapid_key' => env('FIREBASE_WEB_VAPID_KEY'),
];
