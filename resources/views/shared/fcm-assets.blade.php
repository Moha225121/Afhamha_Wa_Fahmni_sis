@php
    try {
        $fcmAssets = app(\Illuminate\Foundation\Vite::class)('resources/js/firebase-messaging.js');
    } catch (\Illuminate\Foundation\ViteException $exception) {
        // Push notifications are optional; an incomplete frontend deployment must not block portal access.
        $fcmAssets = new \Illuminate\Support\HtmlString('');
        \Illuminate\Support\Facades\Log::warning('Firebase messaging assets unavailable; rebuild the frontend assets.', [
            'reason' => $exception->getMessage(),
        ]);
    }
@endphp
{{ $fcmAssets }}
