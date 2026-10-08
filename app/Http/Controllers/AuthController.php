<?php

namespace App\Http\Controllers;

use App\Services\ParentIdentityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        if (! Auth::attempt($credentials + ['status' => 'active'], $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'بيانات الدخول غير صحيحة.'])->onlyInput('email');
        } $request->session()->regenerate();
        $request->session()->forget(ParentIdentityService::SESSION_KEY);
        if ($request->user()->role === 'financial_officer') {
            return redirect()->route('finance.parent-notifications.create');
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
            $request->user()->hasPermission('parent-notifications.send') => route('finance.parent-notifications.create'),
            default => route('admin.dashboard'),
        };

        // Avoid stale intended URLs sending a role into another role's portal.
        return redirect()->to($fallback);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
