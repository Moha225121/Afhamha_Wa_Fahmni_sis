<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class LessonVideoUnavailableException extends HttpException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct(503, match ($reason) {
            'executable_unavailable' => 'خدمة التحقق من الفيديو غير متاحة. حاول لاحقًا أو تواصل مع الإدارة.',
            'process_unavailable', 'process_failed' => 'تعذر تشغيل خدمة التحقق من الفيديو. تواصل مع الإدارة.',
            'receipt_unavailable' => 'تعذر حفظ نتيجة التحقق من الفيديو. حاول لاحقًا.',
            default => throw new \InvalidArgumentException('Unknown video runtime failure.'),
        }, $previous);
    }
}
