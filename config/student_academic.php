<?php

return [
    'exams' => [
        // Until a shared per-exam setting is approved, every exam uses this
        // central default. Raising it is supported by the attempt schema.
        'default_attempt_limit' => 1,
    ],

    'private_files' => [
        'disk' => 'local',
        'max_kilobytes' => 10 * 1024,
        'max_bytes' => 10 * 1024 * 1024,
        'allowed_extensions' => [
            'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx',
            'jpg', 'jpeg', 'png', 'zip',
        ],
        'allowed_mime_types' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'image/jpeg',
            'image/png',
            'application/zip',
            'application/x-zip-compressed',
        ],
    ],
];
