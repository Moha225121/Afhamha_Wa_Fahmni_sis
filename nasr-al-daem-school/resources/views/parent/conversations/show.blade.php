@extends('parent.layout')

@section('title', 'المحادثة')

@section('content')
    @php($recipient = $conversation->participants->firstWhere('id', '!=', auth()->id()))
    <section class="page-title"><p>{{ $conversation->student?->user?->name ?? 'محادثة' }}</p><h1>{{ $recipient?->name ?? 'المحادثة' }}</h1></section>

    <section class="thread">
        @forelse($conversation->messages as $message)
            <article class="bubble {{ $message->sender_id === auth()->id() ? 'mine' : '' }}">
                <p>{{ $message->body }}</p>
                <span>{{ $message->sender?->name }} · {{ $message->created_at->format('Y-m-d H:i') }}</span>
            </article>
        @empty
            <p class="muted-line">لا توجد رسائل في هذه المحادثة.</p>
        @endforelse
    </section>

    @if($conversation->status === 'open')
        <form method="post" action="{{ route('parent.conversations.messages.store', $conversation) }}" class="message-composer">
            @csrf
            <textarea name="body" required maxlength="3000" rows="3" placeholder="اكتب ردك"></textarea>
            <button type="submit">إرسال</button>
        </form>
    @else
        <p class="muted-line">هذه المحادثة مغلقة.</p>
    @endif
@endsection
