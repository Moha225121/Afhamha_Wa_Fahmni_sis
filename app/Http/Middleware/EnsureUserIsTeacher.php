<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsTeacher
{
    public function handle(Request $request, Closure $next): Response
    {
        $teacher = $request->user()?->teacher;
        abort_unless($request->user()?->isTeacher() && $teacher?->status === 'active', 403);

        return $next($request);
    }
}
