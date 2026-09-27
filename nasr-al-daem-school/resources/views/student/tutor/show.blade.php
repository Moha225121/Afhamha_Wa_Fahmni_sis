@extends('student.layout')

@section('title', $conversation->title)

@section('content')
    <div class="tutor-layout">
        <aside class="conversation-sidebar" aria-label="المحادثات">
            <form method="post" action="{{ route('student.tutor.conversations.store') }}" data-tutor-form data-sending-message="يتم إنشاء المحادثة.">
                @csrf
                <button type="submit" data-submit-label="محادثة جديدة" data-sending-label="جارٍ الإنشاء...">محادثة جديدة</button>
            </form>
            @foreach($conversations as $item)
                <a href="{{ route('student.tutor.show', $item) }}" @if($item->is($conversation)) aria-current="page" class="active" @endif>{{ $item->title }}</a>
            @endforeach
            <a href="{{ route('student.tutor.index') }}">كل المحادثات</a>
        </aside>

        <section class="chat-panel">
            <header>
                <p>المعلّم الذكي</p>
                <h1>{{ $conversation->title }}</h1>
            </header>

            @if($errors->any())
                <div class="notice error" role="alert">{{ $errors->first() }}</div>
            @endif

            <ol class="chat-history" aria-live="polite">
                @forelse($messages as $message)
                    <li class="chat-message {{ $message->role === 'user' ? 'from-student' : 'from-tutor' }}">
                        <strong>{{ $message->role === 'user' ? 'أنت' : 'المعلّم الذكي' }}</strong>
                        <p data-tutor-message-content>{{ $message->content }}</p>
                        @if($message->role === 'user' && $message->delivery_status === 'pending')
                            <span class="message-status">قيد المعالجة</span>
                            <button type="button" class="retry-message-button" data-tutor-retry data-tutor-request-id="{{ $message->client_request_id }}">متابعة الطلب نفسه</button>
                        @elseif($message->role === 'user' && $message->delivery_status === 'failed')
                            <span class="message-status">لم يكتمل الطلب</span>
                            <button type="button" class="retry-message-button" data-tutor-retry>إعادة المحاولة كطلب جديد</button>
                        @endif
                    </li>
                @empty
                    <li class="empty-state">
                        <h2>ابدأ بالسؤال</h2>
                        <p>اكتب سؤالًا واضحًا حول ما تريد فهمه.</p>
                    </li>
                @endforelse
            </ol>

            @if($messages->hasPages())
                <nav class="student-pagination chat-pagination" aria-label="صفحات رسائل المحادثة">
                    @if($messages->onFirstPage())
                        <span aria-disabled="true">الأحدث</span>
                    @else
                        <a href="{{ $messages->previousPageUrl() }}" rel="prev">الأحدث</a>
                    @endif

                    @if($messages->hasMorePages())
                        <a href="{{ $messages->nextPageUrl() }}" rel="next">الأقدم</a>
                    @else
                        <span aria-disabled="true">الأقدم</span>
                    @endif
                </nav>
            @endif

            <form class="chat-composer" method="post" action="{{ route('student.tutor.messages.store', $conversation) }}" data-tutor-form data-sending-message="يتم إرسال السؤال بأمان عبر الخادم.">
                @csrf
                <input type="hidden" name="request_id" value="{{ old('request_id', $messageRequestId) }}" data-new-request-id="{{ $messageRequestId }}">
                <label for="tutor-message">سؤالك</label>
                <textarea id="tutor-message" name="message" rows="3" minlength="{{ $messageMinLength }}" maxlength="{{ $messageMaxLength }}" required placeholder="اكتب سؤالك هنا...">{{ old('message') }}</textarea>
                <button type="submit" data-submit-label="إرسال السؤال" data-sending-label="جارٍ الإرسال...">إرسال السؤال</button>
                <span class="sending-status" aria-live="polite"></span>
            </form>
        </section>
    </div>
@endsection
