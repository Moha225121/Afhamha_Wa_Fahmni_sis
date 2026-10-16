@extends('parent.layout')
@section('title', 'الإشعارات')
@section('content')
<section class="parent-notification-page-heading">
    <div><p>كل جديد من المدرسة</p><h1>الإشعارات <span class="parent-notification-total">{{ $unreadCount }} جديدة</span></h1><span>أخبار المدرسة وتحديثات أبنائك في مكان واحد.</span></div>
    <form method="post" action="{{ route('parent.notifications.read-all') }}">
        @csrf @method('patch')
        <button class="parent-notification-mark-all" @disabled(!$unreadCount)>تعليم الكل كمقروء</button>
    </form>
</section>
@if(session('success'))<p class="notice success" role="status">{{ session('success') }}</p>@endif
    <section class="list-section">
        @if($hasPushConfiguration)
            <button type="button" id="enable-push" class="secondary-action">تفعيل إشعارات الهاتف</button>
            <p id="push-status" class="muted-line">ستصل التنبيهات للمتصفح أو للتطبيق المثبت بعد منح الإذن.</p>
        @else
            <p class="muted-line">إشعارات الهاتف غير مفعلة حاليًا. يمكنك متابعة كل الإشعارات هنا.</p>
        @endif
    </section>

<section class="parent-notification-feed" aria-label="قائمة الإشعارات">
    <nav class="parent-notification-feed-tabs" aria-label="تصفية الإشعارات">
        <a href="{{ route('parent.notifications') }}" class="{{ request()->boolean('unread') ? '' : 'is-active' }}" @unless(request()->boolean('unread')) aria-current="page" @endunless>الكل</a>
        <a href="{{ route('parent.notifications', ['unread' => 1]) }}" class="{{ request()->boolean('unread') ? 'is-active' : '' }}" @if(request()->boolean('unread')) aria-current="page" @endif>غير المقروءة <span>{{ $unreadCount }}</span></a>
    </nav>
    @forelse($notifications as $notification)
        <form method="post" action="{{ route('parent.notifications.read', $notification->id) }}">
            @csrf @method('patch')
            <button type="submit" class="parent-notification-row parent-notification-row--full {{ $notification->read_at ? '' : 'is-unread' }}">
                <span class="parent-notification-icon" aria-hidden="true">@include('parent.partials.notification-icon')</span>
                <span class="parent-notification-copy">
                    <span class="parent-notification-source">{{ isset($notification->data['announcement_id']) ? 'إعلان من إدارة المدرسة' : 'تحديث الحساب' }}</span>
                    <strong>{{ $notification->data['title'] ?? 'إشعار جديد' }}</strong>
                    <span>{{ \Illuminate\Support\Str::limit($notification->data['body'] ?? $notification->data['message'] ?? '', 220) }}</span>
                    <time datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->locale('ar')->diffForHumans() }}</time>
                    <span class="parent-notification-open">عرض التفاصيل</span>
                </span>
                @unless($notification->read_at)<span class="parent-notification-dot" aria-label="غير مقروء"></span>@endunless
                <span class="parent-notification-row-arrow" aria-hidden="true">‹</span>
            </button>
        </form>
    @empty
        <div class="parent-notification-empty parent-notification-empty--page">
            <span aria-hidden="true">@include('parent.partials.notification-icon')</span>
            <h2>{{ request()->boolean('unread') ? 'أنت مطّلع على كل جديد' : 'لا توجد إشعارات بعد' }}</h2>
            <p>{{ request()->boolean('unread') ? 'قرأت جميع إشعاراتك. سنخبرك عندما يصل إعلان جديد.' : 'عندما تنشر المدرسة إعلانًا لك أو لصفوف أبنائك، ستجده هنا.' }}</p>
            @if(request()->boolean('unread'))<a href="{{ route('parent.notifications') }}">عرض كل الإشعارات</a>@endif
        </div>
    @endforelse
</section>
{{ $notifications->withQueryString()->links() }}
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
