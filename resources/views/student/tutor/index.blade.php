@extends('student.layout')

@section('title', 'المعلّم الذكي')

@section('content')
    <section class="page-title">
        <p>مساعد تعليمي</p>
        <h1>المعلّم الذكي</h1>
    </section>

    <section class="tutor-intro">
        <h2>ابدأ سؤالًا تعليميًا جديدًا</h2>
        <p>ستُحفظ محادثتك في حسابك، ولا يستطيع طالب آخر الاطلاع عليها.</p>
        <form method="post" action="{{ route('student.tutor.conversations.store') }}" data-tutor-form data-sending-message="يتم إنشاء المحادثة.">
            @csrf
            <button type="submit" data-submit-label="محادثة جديدة" data-sending-label="جارٍ الإنشاء...">محادثة جديدة</button>
        </form>
    </section>

    <section class="list-section">
        <div class="section-title"><h2>المحادثات السابقة</h2></div>
        @forelse($conversations as $conversation)
            <a class="list-row" href="{{ route('student.tutor.show', $conversation) }}">
                <div>
                    <strong>{{ $conversation->title }}</strong>
                    <span>{{ $conversation->messages_count }} رسالة · {{ $conversation->updated_at->format('Y-m-d H:i') }}</span>
                </div>
                <b aria-hidden="true">‹</b>
            </a>
        @empty
            <p class="muted-line">لا توجد محادثات بعد.</p>
        @endforelse
    </section>

    @if($conversations->hasPages())
        <nav class="student-pagination" aria-label="صفحات المحادثات">
            @if($conversations->onFirstPage())
                <span aria-disabled="true">السابق</span>
            @else
                <a href="{{ $conversations->previousPageUrl() }}" rel="prev">السابق</a>
            @endif

            @if($conversations->hasMorePages())
                <a href="{{ $conversations->nextPageUrl() }}" rel="next">التالي</a>
            @else
                <span aria-disabled="true">التالي</span>
            @endif
        </nav>
    @endif
@endsection
