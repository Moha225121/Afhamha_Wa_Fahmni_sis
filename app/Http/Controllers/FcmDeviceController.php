<?php

namespace App\Http\Controllers;

use App\Models\FcmDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FcmDeviceController extends Controller
{
    private const ROLES = ['admin', 'teacher', 'student', 'parent'];

    public function status(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, self::ROLES, true), 403);
        $credentials = config('services.firebase.credentials');
        $projectId = config('services.firebase.project_id');

        return response()->json([
            'enabled' => $request->user()->fcmDevices()->exists(),
            'configured' => is_string($projectId) && $projectId !== '' && is_string($credentials) && is_file($credentials),
            'project_id' => $projectId,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, self::ROLES, true), 403);
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:8192'],
            'device_label' => ['nullable', 'string', 'max:120'],
        ]);
        $hash = hash('sha256', $data['token']);
        $device = FcmDevice::firstOrNew(['token_hash' => $hash]);
        $device->forceFill([
            'user_id' => $request->user()->id,
            'token' => $data['token'],
            'device_label' => $data['device_label'] ?? null,
            'last_seen_at' => now(),
        ])->save();

        return response()->json(['ok' => true, 'device_id' => $device->id]);
    }

    public function destroy(Request $request, FcmDevice $device): JsonResponse
    {
        abort_unless($device->user_id === $request->user()->id, 404);
        $device->delete();

        return response()->json(['ok' => true]);
    }

    public function destroyCurrent(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, self::ROLES, true), 403);
        $data = $request->validate(['token' => ['required', 'string', 'max:8192']]);
        $request->user()->fcmDevices()->where('token_hash', hash('sha256', $data['token']))->delete();

        return response()->json(['ok' => true]);
    }
}
