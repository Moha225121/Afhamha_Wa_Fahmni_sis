<?php

namespace App\Http\Controllers;

use App\Models\FcmDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FcmDeviceController extends Controller
{
    public const SESSION_TOKEN_HASH = 'fcm_device_token_hash';

    public function status(Request $request): JsonResponse
    {
        abort_unless(FcmDevice::canReceive($request->user()), 403);
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
        abort_unless(FcmDevice::canReceive($request->user()), 403);
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:8192'],
            'device_label' => ['nullable', 'string', 'max:120'],
        ]);
        $hash = hash('sha256', $data['token']);
        $previousHash = $request->session()->get(self::SESSION_TOKEN_HASH);
        if (is_string($previousHash) && $previousHash !== $hash) {
            $request->user()->fcmDevices()->where('token_hash', $previousHash)->delete();
        }
        $device = FcmDevice::firstOrNew(['token_hash' => $hash]);
        $device->forceFill([
            'user_id' => $request->user()->id,
            'token' => $data['token'],
            'device_label' => $data['device_label'] ?? null,
            'last_seen_at' => now(),
        ])->save();
        $request->session()->put(self::SESSION_TOKEN_HASH, $hash);

        return response()->json(['ok' => true, 'device_id' => $device->id]);
    }

    public function destroy(Request $request, FcmDevice $device): JsonResponse
    {
        abort_unless($device->user_id === $request->user()->id, 404);
        if ($request->session()->get(self::SESSION_TOKEN_HASH) === $device->token_hash) {
            $request->session()->forget(self::SESSION_TOKEN_HASH);
        }
        $device->delete();

        return response()->json(['ok' => true]);
    }

    public function destroyCurrent(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:8192']]);
        $hash = hash('sha256', $data['token']);
        $request->user()->fcmDevices()->where('token_hash', $hash)->delete();
        if ($request->session()->get(self::SESSION_TOKEN_HASH) === $hash) {
            $request->session()->forget(self::SESSION_TOKEN_HASH);
        }

        return response()->json(['ok' => true]);
    }
}
