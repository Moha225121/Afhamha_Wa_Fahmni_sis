@extends('teacher.layout')
@section('title', 'الرسائل المرسلة')
@section('subtitle', '')
@section('actions')<a class="btn primary" href="{{ route('teacher.notifications.index') }}">رسالة جديدة</a>@endsection
@section('content')
<link rel="stylesheet" href="{{ asset('css/teacher-messaging.css') }}">
<section class="tm-result">
    @forelse($dispatches as $entry)
        <a class="tm-sent-message" href="{{ route('teacher.notifications.show', $entry->new_values['dispatch_id']) }}">
            <strong>{{ $entry->new_values['title'] ?? 'رسالة مرسلة' }}</strong>
            <span>المستلمون: {{ $entry->new_values['recipient_count'] ?? 0 }}</span>
            <span class="tm-back-link">متابعة الاستلام والمشاهدة ←</span>
        </a>
    @empty
        <p>لا توجد رسائل مرسلة بعد.</p>
    @endforelse
    {{ $dispatches->links() }}
</section>
@endsection
