@extends('teacher.layout')
@section('title', 'المساعد التعليمي')
@section('subtitle', 'محادثة خاصة لإعداد الدروس والأسئلة والشروحات العامة')
@push('styles')<link rel="stylesheet" href="{{ asset('css/teacher-tutor.css') }}">@endpush
@push('scripts')<script src="{{ asset('js/student-tutor.js') }}" defer></script>@endpush
@section('content')
@inject('messageRenderer', 'App\Support\TutorMessageRenderer')
<div class="teacher-tutor tutor-layout">
    <aside class="conversation-sidebar" aria-label="المحادثات">
        <form method="post" action="{{ route('teacher.tutor.conversations.store') }}" data-tutor-form>
            @csrf
            <input type="hidden" name="request_id" value="{{ $conversationRequestId }}">
            <button type="submit" data-submit-label="محادثة جديدة" data-sending-label="جارٍ الإنشاء...">محادثة جديدة</button>
        </form>
        @foreach($conversations as $item)
            <a href="{{ route('teacher.tutor.show', $item) }}" @if($item->is($conversation)) aria-current="page" class="active" @endif>{{ $item->title }}</a>
        @endforeach
        <a href="{{ route('teacher.tutor.index') }}">كل المحادثات</a>
    </aside>
    <section class="chat-panel">
        <header><h2>{{ $conversation->title }}</h2><p>لا يصل المساعد إلى سجلات الطلاب أو درجاتهم. تجنب إدخال معلومات شخصية.</p></header>
        <ol class="chat-history" aria-live="polite">
            @forelse($messages as $message)
                <li class="chat-message {{ $message->role === 'user' ? 'from-teacher' : 'from-tutor' }}">
                    <strong>{{ $message->role === 'user' ? 'أنت' : 'المساعد التعليمي' }}</strong>
                    @if($message->role === 'assistant')
                        <div class="tutor-markdown" dir="rtl">{{ $messageRenderer->render($message->content) }}</div>
                    @else
                        <p class="teacher-message-content" dir="auto" data-tutor-message-content>{{ $message->content }}</p>
                    @endif
                    @if($message->role === 'user' && $message->delivery_status === 'pending')
                        <span class="message-status">قيد المعالجة</span>
                        <button type="button" data-tutor-retry data-tutor-request-id="{{ $message->client_request_id }}">متابعة الطلب نفسه</button>
                    @elseif($message->role === 'user' && $message->delivery_status === 'failed')
                        <span class="message-status">لم يكتمل الطلب</span>
                        <button type="button" data-tutor-retry>إعادة المحاولة كطلب جديد</button>
                    @endif
                </li>
            @empty
                <li class="empty-state"><h2>ابدأ بالسؤال</h2><p>اكتب سؤالًا تعليميًا عامًا أو اطلب مساعدة في إعداد درس.</p></li>
            @endforelse
        </ol>
        @if($messages->hasPages())
            <nav class="tutor-pagination" aria-label="صفحات رسائل المحادثة">
                @if(!$messages->onFirstPage())<a href="{{ $messages->previousPageUrl() }}" rel="prev">الأحدث</a>@endif
                @if($messages->hasMorePages())<a href="{{ $messages->nextPageUrl() }}" rel="next">الأقدم</a>@endif
            </nav>
        @endif
        <form class="chat-composer" method="post" action="{{ route('teacher.tutor.messages.store', $conversation) }}" data-tutor-form data-sending-message="يتم إرسال السؤال بأمان عبر الخادم.">
            @csrf
            <input type="hidden" name="request_id" value="{{ old('request_id', $messageRequestId) }}" data-new-request-id="{{ $messageRequestId }}">
            <label for="tutor-message">سؤالك</label>
            <textarea id="tutor-message" name="message" rows="4" minlength="{{ $messageMinLength }}" maxlength="{{ $messageMaxLength }}" required placeholder="مثال: اقترح أنشطة لشرح الكسور...">{{ old('message') }}</textarea>
            <button type="submit" data-submit-label="إرسال السؤال" data-sending-label="جارٍ الإرسال...">إرسال السؤال</button>
            <span class="sending-status" aria-live="polite"></span>
        </form>
    </section>
</div>
@endsection
