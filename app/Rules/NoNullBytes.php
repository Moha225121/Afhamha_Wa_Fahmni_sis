<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** PostgreSQL text and JSONB cannot store U+0000; reject it before any writes. */
class NoNullBytes implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && str_contains($value, "\0")) {
            $fail('يحتوي النص على محرف غير صالح. أزله ثم أعد المحاولة.');
        }
    }
}
