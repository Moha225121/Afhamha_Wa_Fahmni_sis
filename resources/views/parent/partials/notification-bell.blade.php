@php($notificationSummary = $parentNotificationSummary)
<details class="parent-notification-bell" data-notification-bell data-summary-url="{{ route('parent.notifications.summary') }}">
    <summary class="header-notifications" aria-label="الإشعارات، {{ $notificationSummary['unread_count'] }} غير مقروءة" aria-controls="parent-notification-panel">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
        <span class="parent-notification-badge" data-parent-unread @if(!$notificationSummary['unread_count']) hidden @endif>{{ $notificationSummary['unread_count'] > 99 ? '99+' : $notificationSummary['unread_count'] }}</span>
    </summary>
    <section class="parent-notification-panel" id="parent-notification-panel" aria-label="آخر الإشعارات">
        <div class="parent-notification-panel__heading">
            <div><h2>الإشعارات</h2><p data-unread-description>{{ $notificationSummary['unread_count'] ? 'لديك '.$notificationSummary['unread_count'].' إشعارات غير مقروءة' : 'أنت مطّلع على كل جديد' }}</p></div>
            <button type="button" class="parent-notification-close" data-notification-close aria-label="إغلاق الإشعارات">×</button>
        </div>
        <div class="parent-notification-toolbar">
            <div class="parent-notification-filters" aria-label="تصفية الإشعارات">
                <button type="button" class="is-active" data-notification-filter="all" aria-pressed="true">الكل</button>
                <button type="button" data-notification-filter="unread" aria-pressed="false">غير المقروءة</button>
            </div>
            <form method="post" action="{{ route('parent.notifications.read-all') }}" data-notification-all @if(!$notificationSummary['unread_count']) hidden @endif>
                @csrf @method('patch')
                <button type="submit" class="parent-notification-text-button">قراءة الكل</button>
            </form>
        </div>
        <div class="parent-notification-preview" data-notification-preview>
            @forelse($notificationSummary['notifications'] as $item)
                <form method="post" action="{{ $item['read_url'] }}" data-notification-read>
                    @csrf @method('patch')
                    <button type="submit" class="parent-notification-row {{ $item['is_read'] ? '' : 'is-unread' }}">
                        <span class="parent-notification-icon" aria-hidden="true">@include('parent.partials.notification-icon')</span>
                        <span class="parent-notification-copy"><strong>{{ $item['title'] }}</strong><span>{{ \Illuminate\Support\Str::limit($item['body'], 90) }}</span><time datetime="{{ $item['created_at'] }}">{{ $item['time_label'] }}</time></span>
                        @unless($item['is_read'])<span class="parent-notification-dot" aria-label="غير مقروء"></span>@endunless
                    </button>
                </form>
            @empty
                <div class="parent-notification-empty"><span aria-hidden="true">@include('parent.partials.notification-icon')</span><strong>لا توجد إشعارات بعد</strong><p>ستظهر هنا إعلانات المدرسة وملاحظات الطالب والاستدعاءات.</p></div>
            @endforelse
        </div>
        <p class="parent-notification-error" data-notification-error role="status" hidden></p>
        <a class="parent-notification-view-all" href="{{ route('parent.notifications') }}">عرض كل الإشعارات <span aria-hidden="true">←</span></a>
    </section>
</details>
<span class="parent-notification-sr" data-notification-live role="status" aria-live="polite"></span>
<script type="application/json" id="parent-notification-initial">@json($notificationSummary)</script>
