@extends($layout)
@section('title', 'المحادثة')
@include('shared.chat.assets')
@section('content')
@php($recipient = $conversation->participants->firstWhere('id', '!=', auth()->id()))
<section class="school-chat chat-room" data-chat-room data-poll-url="{{ route($routePrefix.'.conversations.messages', $conversation) }}">
    <header class="chat-room-heading">
        <a class="chat-back-icon" href="{{ route($routePrefix === 'parent' ? 'parent.messages' : $routePrefix.'.conversations.index') }}" aria-label="العودة إلى المحادثات"><x-portal-icon name="back"/></a>
        <span class="chat-avatar" aria-hidden="true">{{ mb_substr($recipient?->name ?? 'المدرسة', 0, 1) }}</span>
        <div><h1>{{ $recipient?->name ?? 'المدرسة' }}</h1><p>{{ $conversation->student?->user?->name ?? 'محادثة عامة' }} · {{ match($recipient?->role) { 'admin' => 'إدارة المدرسة', 'teacher' => 'معلم الصف', default => 'ولي الأمر' } }}</p></div>
    </header>
    <button class="chat-older" type="button" data-chat-older @unless($hasOlderMessages) hidden @endunless>عرض رسائل أقدم</button>
    <div class="chat-history" role="log" aria-label="رسائل المحادثة" tabindex="0" data-chat-history>
        @php($lastDay = null)
        @forelse($messages as $message)
            @if($lastDay !== $message->created_at->format('Y-m-d'))<p class="chat-day">{{ $message->created_at->isToday() ? 'اليوم' : $message->created_at->locale('ar')->translatedFormat('j F Y') }}</p>@php($lastDay = $message->created_at->format('Y-m-d'))@endif
            <article class="chat-bubble {{ $message->sender_id === auth()->id() ? 'is-mine' : '' }}" data-message-id="{{ $message->id }}"><p dir="auto">{{ $message->body }}</p><time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->format('H:i') }}</time></article>
        @empty
            <p class="chat-start">ابدأ المحادثة بكتابة رسالتك في الأسفل.</p>
        @endforelse
        <div id="chat-bottom"></div>
    </div>
    <button class="chat-jump" type="button" data-chat-jump hidden>رسائل جديدة ↓</button>
    <p class="chat-error" data-chat-error role="status" hidden></p>
    @if($conversation->status === 'open')
        <form class="chat-composer" method="post" action="{{ route($routePrefix.'.conversations.messages.store', $conversation) }}" data-chat-form>
            @csrf<label class="chat-sr" for="chat-body">رسالتك</label><textarea id="chat-body" name="body" required maxlength="3000" rows="1" placeholder="اكتب رسالة…" data-chat-body>{{ old('body') }}</textarea><button type="submit" aria-label="إرسال الرسالة"><x-portal-icon name="send"/><span>إرسال</span></button>
        </form>
    @endif
    <p class="chat-closed" data-chat-closed @if($conversation->status === 'open') hidden @endif>هذه المحادثة مغلقة ولا يمكن إرسال رسائل جديدة.</p>
    <span class="chat-sr" data-chat-live role="status" aria-live="polite"></span>
</section>
<script type="application/json" id="chat-initial-messages">@json($messages->map(fn ($message) => app(\App\Services\SchoolChatService::class)->serialize($message, auth()->user())))</script>
@endsection
