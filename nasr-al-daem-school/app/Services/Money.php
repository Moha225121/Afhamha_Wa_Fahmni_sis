<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class Money
{
    public static function parse(mixed $value, string $field = 'amount'): int
    {
        $value = (string) $value;
        if (! preg_match('/^\d{1,9}(?:\.\d{1,3})?$/D', $value)) {
            throw ValidationException::withMessages([$field => 'أدخل مبلغًا صحيحًا بالدينار حتى 3 خانات عشرية، دون فواصل آلاف.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');

        return ((int) $whole * 1000) + (int) str_pad($fraction, 3, '0');
    }

    public static function format(int $millimes): string
    {
        return number_format($millimes / 1000, 3).' د.ل';
    }
}
