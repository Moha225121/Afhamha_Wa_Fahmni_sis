@extends('parent.layout')

@section('title', 'الواجبات')

@section('content')
    <section class="page-title">
        <p>متابعة فقط</p>
        <h1>الواجبات</h1>
    </section>

    @if($children->isEmpty())
        <section class="empty-state"><h2>لا يوجد أبناء مرتبطون</h2><p>لا يمكن عرض الواجبات قبل ربط طالب بحساب ولي الأمر.</p></section>
    @else
        @include('parent.partials.child-switcher')
        <section class="messages-list section-gap">
            @forelse($assignments as $assignment)
                @php($submission = $assignment->submissions->first())
                <article class="message-card">
                    <span>{{ $assignment->subject?->name ?? 'مادة غير محددة' }} · {{ $assignment->due_at?->format('Y-m-d H:i') ?? 'بدون موعد' }}</span>
                    <h2>{{ $assignment->title }}</h2>
                    @if($assignment->instructions)<p>{{ $assignment->instructions }}</p>@endif
                    <div class="status-line">
                        <b>{{ $submission?->submitted_at ? 'تم التسليم' : 'لم يتم التسليم' }}</b>
                        @if($assignment->attachments->isNotEmpty())<small>{{ $assignment->attachments->count() }} مرفق/مرفقات</small>@endif
                    </div>
                </article>
            @empty
                <section class="empty-state"><h2>لا توجد واجبات حاليًا</h2><p>تظهر واجبات صف الابن هنا عند إدخالها من البوابة التعليمية.</p></section>
            @endforelse
        </section>
    @endif
@endsection
