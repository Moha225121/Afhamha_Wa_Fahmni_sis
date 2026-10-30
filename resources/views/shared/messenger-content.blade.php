@php
    $messengerRole = $inbox['role'];
    $selected = $inbox['selectedThread'] ?? null;
    $threadRows = collect($inbox['threads']);
    $messageRows = collect($inbox['messages']);
    $childQuery = $messengerRole === 'parent' && !empty($inbox['selectedChildId']) ? ['student' => $inbox['selectedChildId']] : [];
    $parentSelectedChild = $messengerRole === 'parent' ? app(\App\Services\ParentMessageScope::class)->children(auth()->user())->firstWhere('id', $inbox['selectedChildId'] ?? null) : null;
    $notificationsLink = $messengerRole === 'teacher' ? route('teacher.notifications.index') : route($messengerRole.'.notifications', $childQuery);
@endphp
<section class="pm-app {{ $selected ? 'pm-app--selected' : '' }}" data-messenger data-role="{{ $messengerRole }}" data-selected-key="{{ $selected['key'] ?? '' }}" data-student-id="{{ $inbox['selectedChildId'] ?? '' }}" aria-label="الرسائل">
    <header class="pm-toolbar">
        <div class="pm-brand"><span class="pm-brand-icon" aria-hidden="true">✉</span><div><h1>الرسائل</h1><p>{{ $messengerRole === 'parent' ? 'تواصل مع معلمي أبنائك' : ($messengerRole === 'teacher' ? 'محادثات أولياء الأمور' : 'رسائل معلميك') }}</p></div></div>
        <div class="pm-toolbar-actions">
            <a href="{{ $notificationsLink }}" class="pm-link">{{ $messengerRole === 'teacher' ? 'إرسال رسالة للطلاب' : 'تنبيهات المدرسة' }}</a>
            <details class="pm-notifications"><summary><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM9 21h6" stroke-linecap="round" stroke-linejoin="round"/></svg><span>إشعارات الهاتف</span></summary><div class="pm-notifications-panel">@include('shared.push-subscription')</div></details>
        </div>
    </header>
    @if($messengerRole === 'parent')
        @include('parent.partials.child-switcher', ['selectedStudent' => $parentSelectedChild])
    @endif
    <div class="pm-shell {{ !$selected ? 'pm-shell--directory' : '' }}">
        <aside class="pm-sidebar {{ $messengerRole === 'parent' ? 'pm-sidebar--search-first' : '' }}" aria-label="قائمة المحادثات">
            @if($messengerRole !== 'parent')
                <div class="pm-sidebar-heading"><h2>المحادثات</h2><span data-pm-total>{{ $threadRows->count() }}</span></div>
            @endif
            <label class="pm-search"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5" stroke-linecap="round"/></svg><input type="search" data-pm-search placeholder="ابحث بالاسم..." aria-label="البحث في المحادثات" autocomplete="off"></label>
            <nav class="pm-threads" data-pm-threads aria-label="اختر محادثة">
                @forelse($threadRows as $thread)
                    <a href="{{ $thread['url'] }}" class="pm-contact {{ ($selected['key'] ?? null) === $thread['key'] ? 'pm-contact--active' : '' }}" data-pm-contact data-search="{{ $thread['name'].' '.$thread['subtitle'] }}" @if(($selected['key'] ?? null) === $thread['key']) aria-current="page" @endif>
                        <span class="pm-avatar" aria-hidden="true">{{ mb_substr($thread['name'], 0, 1) }}</span>
                        <span class="pm-contact-copy"><span class="pm-contact-top"><strong>{{ $thread['name'] }}</strong><time>{{ $thread['time'] }}</time></span><span class="pm-contact-subtitle">{{ $thread['subtitle'] }}</span><span class="pm-contact-bottom"><span>{{ $thread['preview'] }}</span>@if($thread['unread'])<b class="pm-unread" aria-label="{{ $thread['unread'] }} رسائل غير مقروءة">{{ $thread['unread'] }}</b>@endif</span></span>
                    </a>
                @empty
                    <div class="pm-list-empty"><span aria-hidden="true">☷</span><p>{{ $inbox['emptyText'] ?? 'لا توجد محادثات بعد.' }}</p></div>
                @endforelse
            </nav>
            <p class="pm-search-empty" data-pm-search-empty hidden>لا توجد نتائج لهذا الاسم.</p>
        </aside>
        @if($selected)
        <div class="pm-conversation" role="region" aria-label="المحادثة">
                <header class="pm-chat-header">
                    <a class="pm-back" href="{{ route($messengerRole.'.messages', $childQuery) }}" aria-label="{{ $messengerRole === 'parent' ? 'معلمو الابن المحدد' : 'العودة إلى المحادثات' }}" title="{{ $messengerRole === 'parent' ? 'معلمو الابن المحدد' : 'العودة إلى المحادثات' }}">→</a>
                    <span class="pm-avatar" aria-hidden="true">{{ mb_substr($selected['name'], 0, 1) }}</span>
                    <div><h2>{{ $selected['name'] }}</h2><p>{{ $selected['subtitle'] }}</p></div>
                    <button type="button" class="pm-refresh" data-pm-refresh aria-label="تحديث المحادثة" title="تحديث المحادثة">↻</button>
                </header>
                <div class="pm-stream" data-pm-stream data-read-action="{{ $inbox['readAction'] ?? '' }}" data-last-key="{{ $messageRows->last()['key'] ?? '' }}" tabindex="0" aria-label="الرسائل المتبادلة">
                    @if(!empty($inbox['moreUrl']))<a href="{{ $inbox['moreUrl'] }}" class="pm-older">عرض الرسائل الأقدم</a>@endif
                    @php($lastMessageDay = null)
                    @forelse($messageRows as $message)
                        @if(!empty($message['date']) && $lastMessageDay !== $message['date'])
                            <div class="pm-day"><span>{{ $message['date'] }}</span></div>
                            @php($lastMessageDay = $message['date'])
                        @endif
                        <article class="pm-bubble {{ $message['mine'] ? 'pm-bubble--mine' : '' }}" data-message-key="{{ $message['key'] }}" @if(!empty($message['receipt_url'])) data-pm-receipt="{{ $message['receipt_url'] }}" @endif @if(!empty($message['viewed_url'])) data-pm-viewed="{{ $message['viewed_url'] }}" @endif>
                            @if(!empty($message['child_label']))<span class="pm-child-label">{{ $message['child_label'] }}</span>@endif
                            @if(!empty($message['title']) && $message['title'] !== 'رسالة من '.$selected['name'])<strong class="pm-bubble-title">{{ $message['title'] }}</strong>@endif
                            <p>{{ $message['body'] }}</p>
                            <footer><time>{{ $message['time'] }}</time>@if($message['mine'])<span class="pm-message-status {{ ($message['status'] ?? '') === 'viewed' ? 'pm-message-status--viewed' : '' }}">{{ $message['status_label'] ?? 'تم الإرسال' }}</span>@endif</footer>
                        </article>
                    @empty
                        <div class="pm-thread-empty"><p>{{ $messengerRole === 'parent' ? 'لا توجد رسائل بعد. اكتب رسالتك في الأسفل.' : 'ستظهر الرسائل هنا.' }}</p></div>
                    @endforelse
                </div>
                <button class="pm-new-messages" type="button" data-pm-new hidden>رسائل جديدة ↓</button>
                @if($inbox['canSend'] && !empty($inbox['sendAction']))
                    <form method="post" action="{{ $inbox['sendAction'] }}" class="pm-composer" data-pm-composer>
                        @csrf
                        @foreach(($inbox['sendFields'] ?? []) as $field => $value)
                            @if($field !== 'student_id')<input type="hidden" name="{{ $field }}" value="{{ $value }}">@endif
                        @endforeach
                        @if($messengerRole === 'parent')
                            <div class="pm-child-choice"><span>بخصوص</span><strong class="pm-only-child">{{ $parentSelectedChild?->user->name }}</strong><input type="hidden" name="student_id" value="{{ $inbox['selectedChildId'] }}"></div>
                        @endif
                        <div class="pm-compose-row"><label class="pm-message-label"><span class="pm-sr-only">نص الرسالة</span><textarea name="body" rows="1" required placeholder="اكتب رسالة..." aria-label="نص الرسالة" data-pm-body>{{ old('body') }}</textarea></label><button type="submit" class="pm-send" data-pm-send aria-label="إرسال"><span>إرسال</span><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M21 3 9 15M21 3l-7 18-5-6-6-5L21 3Z" stroke-linejoin="round"/></svg></button></div>
                        <span class="pm-send-feedback" data-pm-feedback role="status" hidden></span>
                    </form>
                @else
                    <div class="pm-read-only">{{ $messengerRole === 'student' ? 'رسائل المعلم' : 'المراسلة غير متاحة حاليًا في هذه المحادثة.' }}</div>
                @endif
            <p class="pm-connection" role="status" data-pm-connection hidden>تعذّر تحديث المحادثة. سنحاول مجددًا عند عودة الاتصال.</p>
        </div>
        @endif
    </div>
</section>
