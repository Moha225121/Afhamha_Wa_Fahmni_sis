@extends('parent.layout')
@section('title', 'الإشعارات')
@section('content')
    <section class="page-title"><p>تنبيهات الحساب</p><h1>الإشعارات @if($notifications->total())<small class="notification-count">{{ $notifications->total() }}</small>@endif</h1></section>

    @include('shared.push-subscription')
    @include('parent.partials.child-switcher')

    <section class="messages-list section-gap">
        @forelse($notifications as $notification)
            @php($teacherReceiptUrl = ($notification->data['category'] ?? '') === 'teacher_notification' ? app(\App\Services\TeacherMessageReceiptService::class)->deliveryUrl($notification->id) : null)
            <article class="message-card {{ $notification->read_at ? '' : 'unread-card' }}" @if($teacherReceiptUrl) data-teacher-receipt-url="{{ $teacherReceiptUrl }}" @endif>
                <span>{{ $notification->created_at->format('Y-m-d H:i') }}</span>
                <h2>{{ $notification->data['title'] ?? 'إشعار' }}</h2>
                <p>{{ $teacherReceiptUrl ? \Illuminate\Support\Str::limit($notification->data['body'] ?? '', 220) : ($notification->data['body'] ?? '') }}</p>
                <a class="text-action" href="{{ app(\App\Services\NotificationDestination::class)->for(auth()->user(), $notification->data) }}">عرض التفاصيل</a>
                @if(! $notification->read_at)
                    <form method="post" action="{{ route('parent.notifications.read', ['notification' => $notification, 'student' => $selectedStudent?->id]) }}">@csrf<button type="submit" class="text-action">تحديد كمقروء</button></form>
                @endif
            </article>
        @empty
            <section class="empty-state"><h2>لا توجد إشعارات</h2><p>تظهر هنا رسائل الحساب والتنبيهات الموجهة لك.</p></section>
        @endforelse
    </section>

    {{ $notifications->links() }}
    <script type="module" src="{{ asset('js/teacher-message-receipts.js') }}"></script>
@endsection
