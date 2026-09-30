<?php

return [
    // Zero removes the application-level book size limit. Browser uploads use chunks.
    'max_upload_mb' => (int) env('LIBRARY_MAX_UPLOAD_MB', 0),
    'chunk_bytes' => 8 * 1024 * 1024,
    'upload_ttl_hours' => 24,
    'extensions' => ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'mp4'],
    'book_types' => [
        'textbook' => 'كتاب الطالب', 'workbook' => 'كتاب التدريبات',
        'activity' => 'كتاب النشاط', 'teacher_guide' => 'دليل المعلم',
        'assessment' => 'كتاب تقويم الطالب', 'handwriting' => 'كراسة الخط',
    ],
    'grades' => [
        7 => 'الصف السابع', 8 => 'الصف الثامن', 9 => 'الصف التاسع',
        10 => 'الأول الثانوي', 11 => 'الثاني الثانوي', 12 => 'الثالث الثانوي',
    ],
];
