<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TeacherPortal\Concerns\InteractsWithTeacherScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    use InteractsWithTeacherScope;

    public function edit(Request $request): View
    {
        return view('teacher.profile', ['teacher' => $this->teacher($request)]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $request->user()->update($validated);

        return back()->with('success', 'تم حفظ الملف الشخصي.');
    }
}
