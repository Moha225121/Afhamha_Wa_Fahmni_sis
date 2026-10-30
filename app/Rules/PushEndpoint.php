<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PushEndpoint implements ValidationRule
{
    /** Syntax/literal-address guard; DNS rebinding and outbound egress require a separate deployment review. */
    public static function allowed(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }
        $host = strtolower(rtrim(trim($parts['host'] ?? '', '[]'), '.'));
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $bytes = inet_pton($host);
            if (strlen($bytes) === 16 && substr($bytes, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
                $host = inet_ntop(substr($bytes, 12));
            }

            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
        }
        if (! str_contains($host, '.') || ! preg_match('/[a-z]$/', $host)) {
            return false;
        }
        foreach (['.localhost', '.local', '.internal', '.lan', '.home'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::allowed($value)) {
            $fail('عنوان خدمة إشعارات الويب غير صالح أو يشير إلى شبكة خاصة.');
        }
    }
}
