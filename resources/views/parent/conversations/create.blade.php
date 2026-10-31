@extends('parent.layout')

@section('title', 'بدء محادثة')
@include('shared.chat.assets')

@section('content')
<div class="school-chat">
    <a class="chat-back" href="{{ route('parent.messages') }}"><x-portal-icon name="back"/>العودة إلى الرسائل</a>
    <div class="chat-page-heading"><div><h1>محادثة جديدة</h1><p>اختر الابن، ثم اضغط على الشخص الذي تريد مراسلته</p></div></div>

    @if($children->isEmpty())
        <section class="empty-state"><h2>لا يوجد أبناء مرتبطون</h2><p>لا يمكن بدء محادثة من دون ابن مرتبط بالحساب.</p></section>
    @else
        @include('parent.partials.child-switcher')
        <p class="chat-context">بخصوص الطالب: <strong>{{ $selectedStudent->user->name }}</strong></p>
        <div class="chat-contact-list">
            @forelse($recipients as $recipient)
                <form method="post" action="{{ route('parent.conversations.store') }}">@csrf<input type="hidden" name="student_id" value="{{ $selectedStudent->id }}"><input type="hidden" name="recipient_id" value="{{ $recipient->id }}"><button class="chat-contact chat-recipient" type="submit"><span class="chat-avatar" aria-hidden="true">{{ mb_substr($recipient->name, 0, 1) }}</span><span class="chat-contact-copy"><strong>{{ $recipient->name }}</strong><span class="chat-student">{{ $recipient->role === 'admin' ? 'إدارة المدرسة' : 'معلم الصف' }}</span></span><x-portal-icon name="chat"/></button></form>
            @empty
                <p class="chat-empty">لا يوجد مستلم متاح لهذا الابن حاليًا. يرجى التواصل مع المدرسة.</p>
            @endforelse
        </div>
    @endif
</div>
@endsection
