<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterFcmTokenRequest;
use App\Models\FcmToken;
use App\Models\PushSubscription;
use App\Services\FcmConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FcmTokenController extends Controller
{
    public const DEVICE_COOKIE = 'school_push_device';

    public function store(RegisterFcmTokenRequest $request, FcmConfiguration $configuration): JsonResponse
    {
        if (! $configuration->webEnabled()) {
            return response()->json(['message' => 'Push notifications are not configured.'], 503);
        }

        $data = $request->validated();
        $hash = hash('sha256', $data['token']);
        DB::transaction(function () use ($request, $data, $hash): void {
            // One owner and one transport per browser, including token rotation/account changes.
            FcmToken::where('device_id', $data['device_id'])->where('token_hash', '!=', $hash)->delete();
            FcmToken::updateOrCreate(['token_hash' => $hash], [
                'user_id' => $request->user()->id,
                'device_id' => $data['device_id'],
                'token' => $data['token'],
                'endpoint_hash' => hash('sha256', $data['endpoint']),
                'last_seen_at' => now(),
            ]);
            $endpoints = array_unique([$data['endpoint'], ...($data['legacy_endpoints'] ?? [])]);
            // These are the browser's existing subscriptions, not arbitrary recipient IDs.
            PushSubscription::whereIn('endpoint', $endpoints)->where('user_id', '!=', $request->user()->id)->delete();
            $request->user()->pushSubscriptions()->whereIn('endpoint', $endpoints)->update(['device_id' => $data['device_id']]);
        });

        return response()->json(['ok' => true])->withCookie(cookie(
            self::DEVICE_COOKIE, $data['device_id'], 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'lax',
        ));
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:4096'], 'user_id' => ['prohibited']]);
        // Always scope removal to the current user; never return another user's token.
        $token = $request->user()->fcmTokens()->where('token_hash', hash('sha256', $data['token']))->first();
        if ($token) {
            $request->user()->pushSubscriptions()->where('device_id', $token->device_id)->delete();
            $token->delete();
        }

        return response()->json(['ok' => true]);
    }
}
