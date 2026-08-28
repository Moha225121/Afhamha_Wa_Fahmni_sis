<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TeacherPortal\Concerns\InteractsWithTeacherScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\View\View;

class ProfileController extends Controller
{
    use InteractsWithTeacherScope;

    public function edit(Request $request): View
    {
        return view('teacher.profile', ['teacher' => $this->teacher($request)]);
    }

    public function avatar(Request $request): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user->avatar_path && Storage::disk('public')->exists($user->avatar_path), 404);

        return response()->file(Storage::disk('public')->path($user->avatar_path), [
            'Cache-Control' => 'private, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'specialization' => ['nullable', 'string', 'max:100'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $user = $request->user();
        $user->update(collect($validated)->except(['specialization', 'avatar'])->all());
        if (array_key_exists('specialization', $validated)) {
            $this->teacher($request)->update(['specialization' => $validated['specialization']]);
        }
        if ($request->hasFile('avatar')) {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }
            $user->update(['avatar_path' => $request->file('avatar')->store('teacher-avatars', 'public')]);
        }

        return back()->with('success', 'تم حفظ الملف الشخصي.');
    }
}
