<?php

namespace App\Http\Controllers\GuardianPortal;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\PushSubscription;
use App\Rules\PushEndpoint;
use App\Services\ParentAnnouncementNotificationService;
use App\Services\ParentMessageScope;
use App\Services\ParentNotificationFeed;
use App\Services\ParentPortalContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request, ParentAnnouncementNotificationService $service): View
    {
        $query = $this->feed($request);
        $context = app(ParentPortalContext::class);
        $guardian = $context->guardian($request);
        $children = $context->children($guardian);
        $selectedStudent = $context->selectedStudent($guardian, $children, $request->query('student'));

        return view('parent.notifications', [
            'children' => $children,
            'selectedStudent' => $selectedStudent,
            'notifications' => (clone $query)->when($request->boolean('unread'), fn ($items) => $items->whereNull('read_at'))
                ->latest()->orderByDesc('id')->paginate(20)->withQueryString()->appends($selectedStudent ? ['student' => $selectedStudent->id] : []),
            'unreadCount' => (clone $query)->whereNull('read_at')->count(),
            'vapidPublicKey' => config('services.web_push.public_key'),
            'hasPushConfiguration' => (bool) config('services.web_push.public_key'),
        ]);
    }

    public function summary(Request $request, ParentAnnouncementNotificationService $service): JsonResponse
    {
        $student = $request->filled('student') ? app(ParentMessageScope::class)->selected($request) : null;

        return response()->json($service->summary($request->user(), $request->boolean('unread'), $student))
            ->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, string $notification, ParentAnnouncementNotificationService $service): RedirectResponse|JsonResponse
    {
        $query = $this->feed($request);
        $record = (clone $query)->whereKey($notification)->firstOrFail();
        $record->markAsRead();
        if ($request->expectsJson()) {
            return response()->json(['unread_count' => $query->whereNull('read_at')->count(), 'url' => $service->destination($record)]);
        }

        return redirect()->to($service->destination($record));
    }

    public function readAll(Request $request, ParentAnnouncementNotificationService $service): RedirectResponse|JsonResponse
    {
        $this->feed($request)->whereNull('read_at')->update(['read_at' => now()]);

        return $request->expectsJson() ? response()->json(['unread_count' => 0]) : back()->with('success', 'تم تعليم جميع الإشعارات كمقروءة.');
    }

    public function announcement(Request $request, Announcement $announcement, ParentAnnouncementNotificationService $service): View
    {
        abort_unless(Announcement::visibleToParent($request->user()->guardian)->whereKey($announcement->id)->exists(), 404);
        $service->markAnnouncementRead($request->user(), $announcement);

        return view('parent.announcements.show', compact('announcement'));
    }

    private function feed(Request $request)
    {
        $context = app(ParentPortalContext::class);
        $guardian = $context->guardian($request);
        $student = $context->selectedStudent($guardian, $context->children($guardian), $request->query('student'));

        return app(ParentNotificationFeed::class)->forStudent($request->user(), $student);
    }

    public function storeSubscription(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000', new PushEndpoint],
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
