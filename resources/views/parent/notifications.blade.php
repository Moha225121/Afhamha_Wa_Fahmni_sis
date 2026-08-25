@extends('parent.layout')

@section('title', 'الإشعارات')

@section('content')
    <section class="page-title"><p>تنبيهات الحساب</p><h1>الإشعارات</h1></section>

    <section class="list-section">
        @if($hasPushConfiguration)
            <button type="button" id="enable-push" class="secondary-action">تفعيل إشعارات الهاتف</button>
            <p id="push-status" class="muted-line">ستصل التنبيهات للمتصفح أو للتطبيق المثبت بعد منح الإذن.</p>
        @else
            <p class="muted-line">إشعارات الهاتف بانتظار إعداد مفاتيح VAPID في بيئة التشغيل. إشعارات الحساب داخل التطبيق تعمل الآن.</p>
        @endif
    </section>

    <section class="messages-list section-gap">
        @forelse($notifications as $notification)
            <article class="message-card {{ $notification->read_at ? '' : 'unread-card' }}">
                <span>{{ $notification->created_at->format('Y-m-d H:i') }}</span>
                <h2>{{ $notification->data['title'] ?? 'إشعار' }}</h2>
                <p>{{ $notification->data['body'] ?? '' }}</p>
                @if(! $notification->read_at)
                    <form method="post" action="{{ route('parent.notifications.read', $notification) }}">@csrf<button type="submit" class="text-action">تحديد كمقروء</button></form>
                @endif
            </article>
        @empty
            <section class="empty-state"><h2>لا توجد إشعارات</h2><p>تظهر هنا رسائل الحساب والتنبيهات الموجهة لك.</p></section>
        @endforelse
    </section>

    {{ $notifications->links() }}
@endsection

@if($hasPushConfiguration)
    @push('scripts')
        <script>
        (() => {
            const button = document.getElementById('enable-push');
            const status = document.getElementById('push-status');
            const publicKey = @json($vapidPublicKey);
            const toUint8Array = (value) => {
                const padding = '='.repeat((4 - value.length % 4) % 4);
                const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
                return Uint8Array.from(atob(base64), char => char.charCodeAt(0));
            };

            button?.addEventListener('click', async () => {
                if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
                    status.textContent = 'هذا المتصفح لا يدعم إشعارات الهاتف.';
                    return;
                }
                const permission = await Notification.requestPermission();
                if (permission !== 'granted') {
                    status.textContent = 'لم يتم منح إذن الإشعارات.';
                    return;
                }
                const registration = await navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: toUint8Array(publicKey) });
                const response = await fetch(@json(route('parent.push-subscriptions.store')), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify(subscription.toJSON()),
                });
                status.textContent = response.ok ? 'تم تفعيل إشعارات الهاتف لهذا الجهاز.' : 'تعذر حفظ اشتراك الإشعارات.';
            });
        })();
        </script>
    @endpush
@endif
