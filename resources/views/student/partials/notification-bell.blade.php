@php($notificationSummary = $studentNotificationSummary)
<details class="student-notification-bell" data-notification-bell data-summary-url="{{ route('student.notifications.summary') }}">
    <summary class="header-notifications" aria-label="الإشعارات، {{ $notificationSummary['unread_count'] }} غير مقروءة" aria-controls="student-notification-panel">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
        <span class="student-notification-badge" data-student-unread @if(!$notificationSummary['unread_count']) hidden @endif>{{ $notificationSummary['unread_count'] > 99 ? '99+' : $notificationSummary['unread_count'] }}</span>
    </summary>
    <section class="student-notification-panel" id="student-notification-panel" aria-label="آخر الإشعارات">
        <div class="student-notification-panel__heading">
            <div><h2>الإشعارات</h2><p data-unread-description>{{ $notificationSummary['unread_count'] ? 'لديك '.$notificationSummary['unread_count'].' إشعارات غير مقروءة' : 'أنت مطّلع على كل جديد' }}</p></div>
            <button type="button" class="student-notification-close" data-notification-close aria-label="إغلاق الإشعارات">×</button>
        </div>
        <div class="student-notification-toolbar">
            <div class="student-notification-filters" aria-label="تصفية الإشعارات">
                <button type="button" class="is-active" data-notification-filter="all" aria-pressed="true">الكل</button>
                <button type="button" data-notification-filter="unread" aria-pressed="false">غير المقروءة</button>
            </div>
            <form method="post" action="{{ route('student.notifications.read-all') }}" data-notification-all @if(!$notificationSummary['unread_count']) hidden @endif>
                @csrf @method('patch')
                <button type="submit" class="student-notification-text-button">قراءة الكل</button>
            </form>
        </div>
        <div class="student-notification-preview" data-notification-preview>
            @forelse($notificationSummary['notifications'] as $item)
                <form method="post" action="{{ $item['read_url'] }}" data-notification-read>
                    @csrf @method('patch')
                    <button type="submit" class="student-notification-row {{ $item['is_read'] ? '' : 'is-unread' }}">
                        <span class="student-notification-icon" aria-hidden="true">@include('student.partials.notification-icon')</span>
                        <span class="student-notification-copy"><strong>{{ $item['title'] }}</strong><span>{{ \Illuminate\Support\Str::limit($item['body'], 90) }}</span><time datetime="{{ $item['created_at'] }}">{{ $item['time_label'] }}</time></span>
                        @unless($item['is_read'])<span class="student-notification-dot" aria-label="غير مقروء"></span>@endunless
                    </button>
                </form>
            @empty
                <div class="student-notification-empty"><span aria-hidden="true">@include('student.partials.notification-icon')</span><strong>لا توجد إشعارات بعد</strong><p>ستظهر هنا إعلانات المدرسة الجديدة.</p></div>
            @endforelse
        </div>
        <p class="student-notification-error" data-notification-error role="status" hidden></p>
        <a class="student-notification-view-all" href="{{ route('student.notifications') }}">عرض كل الإشعارات <span aria-hidden="true">←</span></a>
    </section>
</details>
<span class="student-notification-sr" data-notification-live role="status" aria-live="polite"></span>
<script type="application/json" id="student-notification-initial">@json($notificationSummary)</script>
