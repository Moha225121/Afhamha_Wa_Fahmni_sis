@auth
    @if(!session(\App\Services\ParentStudentSession::PARENT_ID))
        @php($fcmConfiguration = app(\App\Services\FcmConfiguration::class))
            <meta name="csrf-token" content="{{ csrf_token() }}">
            <script type="application/json" id="school-push-config">{!! json_encode([
                'firebase' => $fcmConfiguration->webEnabled() ? $fcmConfiguration->publicConfig() : null,
                'vapidKey' => $fcmConfiguration->webEnabled() ? config('fcm.vapid_key') : null,
                'legacyVapidKey' => config('services.web_push.public_key'),
                'storeUrl' => route('fcm-tokens.store', [], false),
                'deleteUrl' => route('fcm-tokens.destroy', [], false),
                'legacyStoreUrl' => route('push-subscriptions.store', [], false),
                'userId' => (string) auth()->id(),
                'loginId' => app(\App\Services\PushLoginSession::class)->id(request()),
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
            <link rel="stylesheet" href="{{ asset('css/push-notifications.css') }}">
            @once('school-push-activation-status')
                <script src="{{ asset('js/push-activation-status.js') }}?v=20261021-1" defer></script>
            @endonce
            @vite('resources/js/app.js')
    @endif
@endauth
