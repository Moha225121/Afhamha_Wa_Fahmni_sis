@extends('student.layout')
@section('title', 'الإشعارات')
@section('content')
<section class="student-notification-page-heading">
    <div><p>كل جديد من مدرستك</p><h1>الإشعارات <span class="student-notification-total">{{ $unreadCount }} جديدة</span></h1><span>إعلاناتك وتحديثاتك الدراسية في مكان واحد.</span></div>
    <form method="post" action="{{ route('student.notifications.read-all') }}">
        @csrf @method('patch')
        <button class="student-notification-mark-all" @disabled(!$unreadCount)>تعليم الكل كمقروء</button>
    </form>
</section>
@if(session('success'))<p class="notice success" role="status">{{ session('success') }}</p>@endif
<section class="student-notification-feed" aria-label="قائمة الإشعارات">
    <nav class="student-notification-feed-tabs" aria-label="تصفية الإشعارات">
        <a href="{{ route('student.notifications') }}" class="{{ request()->boolean('unread') ? '' : 'is-active' }}" @unless(request()->boolean('unread')) aria-current="page" @endunless>الكل</a>
        <a href="{{ route('student.notifications', ['unread' => 1]) }}" class="{{ request()->boolean('unread') ? 'is-active' : '' }}" @if(request()->boolean('unread')) aria-current="page" @endif>غير المقروءة <span>{{ $unreadCount }}</span></a>
    </nav>
    @forelse($notifications as $notification)
        <form method="post" action="{{ route('student.notifications.read', $notification->id) }}">
            @csrf @method('patch')
            <button type="submit" class="student-notification-row student-notification-row--full {{ $notification->read_at ? '' : 'is-unread' }}">
                <span class="student-notification-icon" aria-hidden="true">@include('student.partials.notification-icon')</span>
                <span class="student-notification-copy">
                    <span class="student-notification-source">{{ isset($notification->data['announcement_id']) ? 'إعلان من إدارة المدرسة' : 'تحديث دراسي' }}</span>
                    <strong>{{ $notification->data['title'] ?? 'إشعار جديد' }}</strong>
                    <span>{{ \Illuminate\Support\Str::limit($notification->data['body'] ?? $notification->data['message'] ?? '', 220) }}</span>
                    <time datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->locale('ar')->diffForHumans() }}</time>
                </span>
                @unless($notification->read_at)<span class="student-notification-dot" aria-label="غير مقروء"></span>@endunless
                <span class="student-notification-row-arrow" aria-hidden="true">‹</span>
            </button>
        </form>
    @empty
        <div class="student-notification-empty student-notification-empty--page">
            <span aria-hidden="true">@include('student.partials.notification-icon')</span>
            <h2>{{ request()->boolean('unread') ? 'أنت مطّلع على كل جديد' : 'لا توجد إشعارات بعد' }}</h2>
            <p>{{ request()->boolean('unread') ? 'قرأت جميع إشعاراتك. سنخبرك عندما يصل إعلان جديد.' : 'عندما تنشر المدرسة إعلانًا لك أو لصفك، ستجده هنا.' }}</p>
            @if(request()->boolean('unread'))<a href="{{ route('student.notifications') }}">عرض كل الإشعارات</a>@endif
        </div>
    @endforelse
</section>
{{ $notifications->withQueryString()->links() }}
@endsection
