@extends($layout)
@section('title', 'رسالة من المعلم')
@section('content')
<link rel="stylesheet" href="{{ asset('css/teacher-messaging.css') }}">
<section class="tm-message-detail" data-teacher-viewed-url="{{ route('teacher-messages.viewed', $notification->id, false) }}">
    <a class="tm-back-link" href="{{ route(auth()->user()->role === 'parent' ? 'parent.notifications' : 'student.notifications') }}">العودة إلى الإشعارات</a>
    <h1>{{ $notification->data['title'] ?? 'رسالة من المعلم' }}</h1>
    <p class="tm-helper">من: {{ $senderName }}</p>
    <div class="tm-full-message" dir="auto">{{ $notification->data['body'] ?? '' }}</div>
</section>
<script type="module" src="{{ asset('js/teacher-message-receipts.js') }}"></script>
@endsection
