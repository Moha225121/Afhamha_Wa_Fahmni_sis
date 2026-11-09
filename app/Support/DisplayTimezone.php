<?php

namespace App\Support;

use DateTimeZone;
use Throwable;

final class DisplayTimezone
{
    public static function normalize(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            try {
                return (new DateTimeZone(trim($value)))->getName();
            } catch (Throwable) {
                // Display-only fallback: never change stored timestamps or the application clock.
            }
        }

        return 'Africa/Tripoli';
    }

    public static function name(): string
    {
        return self::normalize(config('app.display_timezone'));
    }
}
