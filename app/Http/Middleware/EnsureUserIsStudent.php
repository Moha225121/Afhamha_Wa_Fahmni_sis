<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsStudent
{
    public function handle(Request $request, Closure $next): Response
    {
        $student = $request->user()?->student;

        abort_unless($request->user()?->isStudent() && $student?->status === 'active', 403);

        return $next($request);
    }
}
