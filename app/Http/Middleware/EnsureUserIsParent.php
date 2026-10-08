<?php

namespace App\Http\Middleware;

use App\Services\ParentIdentityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsParent
{
    public function handle(Request $request, Closure $next): Response
    {
        $guardian = $request->user()?->guardian()->with('user')->first();

        abort_unless($request->user()?->isParent() && $guardian?->status === 'active', 403);
        $request->user()->setRelation('guardian', $guardian);

        $identities = app(ParentIdentityService::class);
        $options = $identities->options($guardian);
        $key = $identities->selectedKey($request, $guardian);
        if ($key === null && ! $request->routeIs('parent.identity.*')) {
            return redirect()->route('parent.identity.create');
        }

        view()->share('parentIdentity', $key ? $options[$key] : null);
        view()->share('parentIdentityOptions', $options);

        return $next($request);
    }
}
