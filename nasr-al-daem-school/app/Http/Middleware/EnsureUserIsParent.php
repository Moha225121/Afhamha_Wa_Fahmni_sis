<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsParent
{
    public function handle(Request $request, Closure $next): Response
    {
        $guardian = $request->user()?->guardian;

        abort_unless($request->user()?->isParent() && $guardian?->status === 'active', 403);

        return $next($request);
    }
}
