<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Rules\PushEndpoint;
use App\Services\ParentStudentSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->status === 'active' && ! $request->session()->has(ParentStudentSession::PARENT_ID), 403);
        $validated = $request->validate([
            'device_id' => ['sometimes', 'uuid'],
            'endpoint' => ['required', 'string', 'max:2000', new PushEndpoint],
            'keys.p256dh' => ['required', 'string', 'max:1000'],
            'keys.auth' => ['required', 'string', 'max:1000'],
            'expirationTime' => ['nullable', 'numeric'],
        ]);

        PushSubscription::where('endpoint', $validated['endpoint'])->where('user_id', '!=', $request->user()->id)->delete();
        $subscription = $request->user()->pushSubscriptions()->updateOrCreate(
            ['endpoint' => $validated['endpoint']],
            [
                'public_key' => $validated['keys']['p256dh'],
                'device_id' => $validated['device_id'] ?? null,
                'auth_token' => $validated['keys']['auth'],
                'content_encoding' => 'aes128gcm',
                'expires_at' => isset($validated['expirationTime']) ? now()->setTimestamp((int) ($validated['expirationTime'] / 1000)) : null,
            ],
        );

        if (isset($validated['device_id'])) {
            $request->user()->fcmTokens()->where('device_id', $validated['device_id'])->delete();
        }
        $response = response()->json(['ok' => true, 'id' => $subscription->id]);
        if (isset($validated['device_id'])) {
            $response->withCookie(cookie(FcmTokenController::DEVICE_COOKIE, $validated['device_id'], 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'lax'));
        }

        return $response;
    }

    public function destroy(Request $request, PushSubscription $subscription): JsonResponse
    {
        abort_unless($subscription->user_id === $request->user()->id, 404);
        if ($subscription->device_id) {
            $request->user()->fcmTokens()->where('device_id', $subscription->device_id)->delete();
            $request->user()->pushSubscriptions()->where('device_id', $subscription->device_id)->delete();
        } else {
            $subscription->delete();
        }

        return response()->json(['ok' => true]);
    }
}
