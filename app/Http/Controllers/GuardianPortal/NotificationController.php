<?php

namespace App\Http\Controllers\GuardianPortal;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('parent.notifications', [
            'notifications' => $request->user()->notifications()->latest()->paginate(30),
            'vapidPublicKey' => config('services.web_push.public_key'),
            'hasPushConfiguration' => (bool) config('services.web_push.public_key'),
        ]);
    }

    public function read(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        abort_unless($notification->notifiable_type === $request->user()::class && $notification->notifiable_id === $request->user()->id, 404);
        $notification->markAsRead();

        return back();
    }

    public function storeSubscription(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:1000'],
            'keys.auth' => ['required', 'string', 'max:1000'],
            'expirationTime' => ['nullable', 'numeric'],
        ]);

        $request->user()->pushSubscriptions()->updateOrCreate(
            ['endpoint' => $validated['endpoint']],
            [
                'public_key' => $validated['keys']['p256dh'],
                'auth_token' => $validated['keys']['auth'],
                'content_encoding' => 'aes128gcm',
                'expires_at' => isset($validated['expirationTime']) ? now()->setTimestamp((int) ($validated['expirationTime'] / 1000)) : null,
            ],
        );

        return response()->json(['ok' => true]);
    }

    public function destroySubscription(Request $request, PushSubscription $subscription): JsonResponse
    {
        abort_unless($subscription->user_id === $request->user()->id, 404);
        $subscription->delete();

        return response()->json(['ok' => true]);
    }
}
