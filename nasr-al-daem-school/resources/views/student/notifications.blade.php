@extends('student.layout')
@section('title', 'الإشعارات')
@section('content')
<section class="page-title"><p>{{ $student->user->name }}</p><h1>الإشعارات</h1></section>
@if(session('success'))<div class="notice success">{{ session('success') }}</div>@endif
<section class="metrics-grid"><article class="metric"><span>غير مقروءة</span><strong>{{ $unreadCount }}</strong></article><article class="metric"><span>الإجمالي</span><strong>{{ $notifications->total() }}</strong></article></section>
<section class="messages-list section-space">
@forelse($notifications as $notification)
<article class="message-card"><span>{{ $notification->created_at->format('Y-m-d H:i') }}</span><h2>{{ $notification->data['title'] ?? 'إشعار' }}</h2><p>{{ $notification->data['message'] ?? $notification->data['body'] ?? '' }}</p>@if(!$notification->read_at)<form method="post" action="{{ route('student.notifications.read', $notification->id) }}">@csrf @method('patch')<button class="action-link">تعليم كمقروء</button></form>@endif</article>
@empty<div class="empty-state"><p>لا توجد إشعارات شخصية.</p></div>@endforelse
</section>
{{ $notifications->links() }}
<section class="list-section"><div class="section-title"><h2>إعلانات الصف والطلاب</h2></div>@forelse($announcements as $announcement)<article class="message-row"><div><strong>{{ $announcement->title }}</strong><span>{{ $announcement->content }}</span></div></article>@empty<p class="muted-line">لا توجد إعلانات منشورة.</p>@endforelse</section>
@endsection
