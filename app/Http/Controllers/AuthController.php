<?php

namespace App\Http\Controllers;

use App\Services\ParentIdentityService;
use App\Services\PushLoginSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class AuthController
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'string', 'max:254'], 'password' => ['required', 'string']]);
        $remember = $request->boolean('remember');
        $identityKey = 'login:identity:'.hash('sha256', mb_strtolower($credentials['email']).'|'.$request->ip());
        $ipKey = 'login:ip:'.hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($identityKey, 5) || RateLimiter::tooManyAttempts($ipKey, 50)) {
            $seconds = max(RateLimiter::availableIn($identityKey), RateLimiter::availableIn($ipKey));
            abort(429, 'محاولات دخول كثيرة. انتظر قليلاً ثم أعد المحاولة.', ['Retry-After' => max(1, $seconds)]);
        }

        if (! Auth::attempt($credentials + ['status' => 'active'], $remember)) {
            RateLimiter::hit($identityKey, 60);
            RateLimiter::hit($ipKey, 60);

            return back()->withErrors(['email' => 'بيانات الدخول غير صحيحة.'])->onlyInput('email');
        }
        RateLimiter::clear($identityKey);

        $request->session()->regenerate();
        app(PushLoginSession::class)->start($request);
        $request->session()->forget(ParentIdentityService::SESSION_KEY);
        if ($request->user()->role === 'financial_officer') {
            return redirect()->route('finance.index');
        }
        $request->user()->update(['last_login_at' => now()]);

        if ($request->user()->isParent() && $request->user()->guardian?->status === 'active'
            && app(ParentIdentityService::class)->selectedKey($request, $request->user()->guardian) === null) {
            return redirect()->route('parent.identity.create');
        }

        $fallback = match (true) {
            $request->user()->isParent() => route('parent.dashboard'),
            $request->user()->isStudent() => route('student.dashboard'),
            $request->user()->isTeacher() => route('teacher.dashboard'),
            $request->user()->isSupervisor() => route('supervisor.dashboard'),
            $request->user()->isAdmin() => route('admin.dashboard'),
            $request->user()->role === 'staff' => route('staff.dashboard'),
            $request->user()->hasPermission('parent-notifications.send') => route('finance.parent-notifications.create'),
            default => route('admin.dashboard'),
        };

        // Avoid stale intended URLs sending a role into another role's portal.
        return redirect()->to($fallback);
    }

    public function destroy(Request $request): RedirectResponse
    {
        if ($deviceId = $request->cookie(FcmTokenController::DEVICE_COOKIE)) {
            $request->user()->fcmTokens()->where('device_id', $deviceId)->delete();
            $request->user()->pushSubscriptions()->where('device_id', $deviceId)->delete();
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withoutCookie(FcmTokenController::DEVICE_COOKIE);
    }
}
