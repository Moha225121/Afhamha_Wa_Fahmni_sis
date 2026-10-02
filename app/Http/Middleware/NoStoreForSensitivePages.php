<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NoStoreForSensitivePages
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->user() && $request->route()) {
            $routeName = (string) $request->route()->getName();

            if ($routeName !== '' && preg_match('/^(teacher|student|parent|admin|supervisor)\./', $routeName) === 1) {
                $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
                $response->headers->set('Pragma', 'no-cache');
                $response->headers->set('Expires', '0');
            }
        }

        return $response;
    }
}
