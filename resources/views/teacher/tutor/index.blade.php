@extends('teacher.layout')
@section('title', 'المساعد التعليمي')
@section('subtitle', 'مساعدة في إعداد الدروس والأسئلة والشروحات العامة')
@push('styles')<link rel="stylesheet" href="{{ asset('css/teacher-tutor.css') }}">@endpush
@push('scripts')<script src="{{ asset('js/student-tutor.js') }}" defer></script>@endpush
@section('content')
<div class="teacher-tutor">
    <section class="tutor-intro">
        <h2>ابدأ محادثة تعليمية</h2>
        <p>محادثاتك خاصة بحسابك. لا يصل المساعد إلى سجلات الطلاب أو درجاتهم؛ استخدم أسئلة تعليمية عامة دون معلومات شخصية.</p>
        <form method="post" action="{{ route('teacher.tutor.conversations.store') }}" data-tutor-form>
            @csrf
            <input type="hidden" name="request_id" value="{{ $conversationRequestId }}">
            <button type="submit" data-submit-label="محادثة جديدة" data-sending-label="جارٍ الإنشاء...">محادثة جديدة</button>
        </form>
    </section>
    <section class="tutor-conversations" aria-label="المحادثات السابقة">
        <h2>المحادثات السابقة</h2>
        @forelse($conversations as $conversation)
            <a class="tutor-conversation" href="{{ route('teacher.tutor.show', $conversation) }}">
                <strong>{{ $conversation->title }}</strong>
                <span>{{ $conversation->messages_count }} رسالة · {{ $conversation->updated_at->copy()->timezone(\App\Support\DisplayTimezone::name())->format('Y-m-d H:i') }}</span>
            </a>
        @empty
            <p>لا توجد محادثات بعد.</p>
        @endforelse
    </section>
    @if($conversations->hasPages())
        <nav class="tutor-pagination" aria-label="صفحات المحادثات">
            @if(!$conversations->onFirstPage())<a href="{{ $conversations->previousPageUrl() }}" rel="prev">السابق</a>@endif
            @if($conversations->hasMorePages())<a href="{{ $conversations->nextPageUrl() }}" rel="next">التالي</a>@endif
        </nav>
    @endif
</div>
@endsection
