<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SchoolEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) > 254) {
            $fail('البريد المدرسي غير صالح.');

            return;
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        // The requested awf_school naming convention is an internal school account identifier.
        if (! preg_match('/^[a-z0-9][a-z0-9._+-]{0,63}@[a-z0-9][a-z0-9_.-]*\.[a-z]{2,}$/iD', $value) || str_contains($value, '..')) {
            $fail('البريد المدرسي غير صالح.');
        }
    }
}
