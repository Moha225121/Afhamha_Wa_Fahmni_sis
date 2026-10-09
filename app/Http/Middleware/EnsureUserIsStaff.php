<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserIsStaff
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->status === 'active' && in_array($request->user()->role, ['staff', 'financial_officer', 'admin'], true), 403);

        return $next($request);
    }
}
