@extends('parent.layout')

@section('title', 'المحادثات')

@section('content')
    <section class="page-title"><p>تواصل آمن</p><h1>المحادثات</h1></section>

    @if($children->isEmpty())
        <section class="empty-state"><h2>لا يوجد أبناء مرتبطون</h2><p>يجب ربط ابن بالحساب قبل بدء محادثة أكاديمية.</p></section>
    @else
        @include('parent.partials.child-switcher')
        <a class="primary-action" href="{{ route('parent.conversations.create', ['student' => $selectedStudent?->id]) }}">بدء محادثة جديدة</a>
        <section class="messages-list section-gap">
            @forelse($conversations as $conversation)
                @php($recipient = $conversation->participants->firstWhere('id', '!=', auth()->id()))
                <a class="message-card conversation-card" href="{{ route('parent.conversations.show', $conversation) }}">
                    <span>{{ $conversation->student?->user?->name ?? 'محادثة عامة' }}</span>
                    <h2>{{ $recipient?->name ?? 'محادثة' }}</h2>
                    <p>{{ $conversation->latestMessage?->body ?? $conversation->subject ?? 'لا توجد رسائل بعد.' }}</p>
                    <small>{{ $conversation->last_message_at?->format('Y-m-d H:i') ?? '' }}</small>
                </a>
            @empty
                <section class="empty-state"><h2>لا توجد محادثات</h2><p>يمكنك بدء محادثة مع إدارة المدرسة أو معلم الابن المسند إلى صفه.</p></section>
            @endforelse
        </section>
    @endif
@endsection
