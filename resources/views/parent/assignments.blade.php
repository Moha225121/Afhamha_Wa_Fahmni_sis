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
        @include('parent.partials.subject-filter')
        <section class="messages-list section-gap">
            @forelse($assignments as $assignment)
                @php($submission = $assignment->submissions->first())
                <article class="message-card">
                    <div class="academic-item-heading">
                        <h2>{{ $assignment->title }}</h2>
                        <span class="academic-subject">المادة: {{ $assignment->subject_label }}</span>
                    </div>
                    <span>موعد التسليم: {{ $assignment->due_at?->format('Y-m-d H:i') ?? 'بدون موعد' }}</span>
                    @if($assignment->instructions || $assignment->description)<x-assignment-content :content="$assignment->instructions ?: $assignment->description" />@endif
                    <div class="status-line">
                        <b>{{ $submission?->submitted_at ? 'تم التسليم' : 'لم يتم التسليم' }}</b>
                        @if($assignment->attachments->isNotEmpty())<small>{{ $assignment->attachments->count() }} مرفق/مرفقات</small>@endif
                    </div>
                </article>
            @empty
                <section class="empty-state"><h2>{{ $selectedSubjectId ? 'لا توجد واجبات لهذه المادة' : 'لا توجد واجبات حاليًا' }}</h2><p>تظهر واجبات صف الابن هنا عند نشرها.</p></section>
            @endforelse
        </section>
    @endif
@endsection
