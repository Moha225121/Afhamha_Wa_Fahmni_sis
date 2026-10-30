<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class LessonAttachmentSize implements ValidationRule
{
    public static function maximumForName(string $name, ?string $detectedMime = null): int
    {
        $maximum = (int) config('lesson_uploads.max_bytes');

        return strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'mp4' || $detectedMime === 'video/mp4'
            ? min($maximum, (int) config('lesson_uploads.video_max_bytes', $maximum))
            : $maximum;
    }

    public static function messageForName(string $name, ?string $detectedMime = null): string
    {
        return 'الحد الأقصى '.(self::maximumForName($name, $detectedMime) / 1048576).' MiB '.
            (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'mp4' || $detectedMime === 'video/mp4' ? 'لكل فيديو.' : 'لكل ملف.');
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value instanceof UploadedFile && $value->isValid()) {
            // The original extension and client Content-Type can both be misleading.
            $name = $value->getClientOriginalName();
            $detectedMime = $value->getMimeType();
            if ($value->getSize() > self::maximumForName($name, $detectedMime)) {
                $fail(self::messageForName($name, $detectedMime));
            }
        }
    }
}
