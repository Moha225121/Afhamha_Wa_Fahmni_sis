<?php

return [
    'max_bytes' => 512 * 1024 * 1024,
    'video_max_bytes' => 264 * 1024 * 1024,
    'chunk_bytes' => 4 * 1024 * 1024,
    'total_bytes' => 1100 * 1024 * 1024,
    'ttl_hours' => 24,
    'mimes' => 'pdf,doc,docx,ppt,pptx,xls,xlsx,jpg,jpeg,png,webp,heic,heif,mp4',
    'video' => [
        'binary' => env('LESSON_FFMPEG_BINARY', 'ffmpeg'),
        // Keep a finite wall-clock deadline even when the environment value is invalid.
        'timeout_seconds' => filter_var(env('LESSON_VIDEO_TIMEOUT_SECONDS', 120), FILTER_VALIDATE_INT, [
            'options' => ['default' => 120, 'min_range' => 1, 'max_range' => 300],
        ]),
        'max_alloc_bytes' => 64 * 1024 * 1024,
        'max_pixels' => 3840 * 2160,
        'max_output_bytes' => 64 * 1024,
    ],
];
