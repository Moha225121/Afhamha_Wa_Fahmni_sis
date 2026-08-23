<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(): View
    {
        return view('admin.profile');
    }

    public function update(Request $r): RedirectResponse
    {
        $u = $r->user();
        $d = $r->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', Rule::unique('users')->ignore($u)], 'phone' => ['nullable', 'string', 'max:30'], 'current_password' => ['nullable', 'required_with:password', 'current_password'], 'password' => ['nullable', 'string', 'min:8', 'confirmed']]);
        unset($d['current_password']);
        if (! $r->filled('password')) {
            unset($d['password']);
        }$u->update($d);

        return back()->with('success', 'تم تحديث الملف الشخصي.');
    }
}
