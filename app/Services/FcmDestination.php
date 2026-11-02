<?php

namespace App\Services;

use App\Models\User;

class FcmDestination
{
    public static function forUser(User $user, mixed $url): string
    {
        $fallback = match ($user->role) {
            'parent' => route('parent.notifications', [], false),
            'student' => route('student.notifications', [], false),
            'teacher' => route('teacher.dashboard', [], false),
            default => route('admin.notifications.index', [], false),
        };

        if (! is_string($url) || $url === '' || str_starts_with($url, '//')) {
            return $fallback;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $prefix = match ($user->role) {
            'parent' => '/parent/',
            'student' => '/student/',
            'teacher' => '/teacher/',
            'admin' => '/admin/',
            default => null,
        };

        if (! is_string($path) || ! $prefix || ! str_starts_with($path, $prefix)
            || str_contains($path, '\\') || preg_match('~(?:^|/)\.{1,2}(?:/|$)|%(?:2e|2f|5c|00)~i', $path)) {
            return $fallback;
        }

        $query = parse_url($url, PHP_URL_QUERY);
        $fragment = parse_url($url, PHP_URL_FRAGMENT);

        return $path.($query !== null ? '?'.$query : '').($fragment !== null ? '#'.$fragment : '');
    }
}
