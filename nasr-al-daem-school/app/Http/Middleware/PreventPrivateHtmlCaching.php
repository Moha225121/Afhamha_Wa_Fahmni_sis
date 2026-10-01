<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PreventPrivateHtmlCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof BinaryFileResponse
            && ! $response instanceof StreamedResponse
            && $request->user()
            && in_array('auth', $request->route()?->gatherMiddleware() ?? [], true)
            && str_starts_with(strtolower((string) $response->headers->get('Content-Type')), 'text/html')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
