<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TeacherPortal\Concerns\InteractsWithTeacherScope;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
            'avatar_data' => ['nullable', 'string', 'regex:/^data:image\/(png|jpeg|jpg|webp);base64,.+$/'],
            'remove_avatar' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $previousAvatarPath = $user->avatar_path;
        $nextAvatarPath = $previousAvatarPath;
        $user->update(collect($validated)->except(['specialization', 'avatar', 'avatar_data', 'remove_avatar'])->all());
        if (array_key_exists('specialization', $validated)) {
            $this->teacher($request)->update(['specialization' => $validated['specialization']]);
        }

        // Handle cropped image data (base64)
        if ($validated['avatar_data'] ?? false) {
            $imageData = $validated['avatar_data'];
            $base64Image = preg_replace('/^data:image\/(?:png|jpeg|jpg|webp);base64,/', '', $imageData);
            $imageContent = base64_decode($base64Image, true);
            abort_unless(is_string($imageContent) && $imageContent !== '', 422, 'تعذر قراءة الصورة. اختر صورة أخرى.');
            $nextAvatarPath = 'teacher-avatars/'.bin2hex(random_bytes(16)).'.png';
            Storage::disk('public')->put($nextAvatarPath, $imageContent);
            $user->update(['avatar_path' => $nextAvatarPath]);
        } elseif ($request->hasFile('avatar')) {
            $nextAvatarPath = $request->file('avatar')->store('teacher-avatars', 'public');
            $user->update(['avatar_path' => $nextAvatarPath]);
        } elseif ($request->boolean('remove_avatar')) {
            $nextAvatarPath = null;
            $user->update(['avatar_path' => null]);
        }

        if ($previousAvatarPath && $previousAvatarPath !== $nextAvatarPath
            && ! User::where('avatar_path', $previousAvatarPath)->exists()) {
            Storage::disk('public')->delete($previousAvatarPath);
        }

        return back()->with('success', 'تم حفظ الملف الشخصي.');
    }
}
