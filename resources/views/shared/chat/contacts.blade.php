<div class="chat-search"><x-portal-icon name="search"/><input type="search" placeholder="ابحث عن محادثة" aria-label="البحث في المحادثات" data-chat-search></div>
<div class="chat-contact-list">
@forelse($conversations as $conversation)
    @php($recipient = $conversation->participants->firstWhere('id', '!=', auth()->id()))
    <a class="chat-contact {{ $conversation->unread_count ? 'has-unread' : '' }}" data-chat-contact data-chat-search-text="{{ $recipient?->name }} {{ $conversation->student?->user?->name }} {{ $conversation->latestMessage?->body }}" href="{{ route(auth()->user()->role.'.conversations.show', $conversation) }}">
        <span class="chat-avatar" aria-hidden="true">{{ mb_substr($recipient?->name ?? 'المدرسة', 0, 1) }}</span>
        <div class="chat-contact-copy">
            <div class="chat-contact-top"><strong>{{ $recipient?->name ?? 'المدرسة' }}</strong><time>{{ $conversation->last_message_at?->isToday() ? $conversation->last_message_at->format('H:i') : $conversation->last_message_at?->format('m-d') }}</time></div>
            <span class="chat-student">{{ $conversation->student?->user?->name ?? 'محادثة عامة' }}</span>
            <p>{{ $conversation->latestMessage?->sender_id === auth()->id() ? 'أنت: ' : '' }}{{ \Illuminate\Support\Str::limit($conversation->latestMessage?->body ?? 'ابدأ المحادثة بكتابة رسالة.', 90) }}</p>
        </div>
        @if($conversation->unread_count)<b class="chat-unread" aria-label="{{ $conversation->unread_count }} رسائل جديدة">{{ $conversation->unread_count }}</b>@endif
    </a>
@empty
    <div class="chat-empty"><x-portal-icon name="chat"/><h2>لا توجد محادثات بعد</h2><p>{{ auth()->user()->isParent() ? 'ابدأ محادثة مع إدارة المدرسة أو معلم ابنك.' : 'ستظهر هنا رسائل أولياء الأمور المرسلة إليك.' }}</p></div>
@endforelse
</div>
<p class="chat-no-match" data-chat-no-match hidden>لا توجد محادثات مطابقة للبحث.</p>
