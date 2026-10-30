@extends('parent.layout')

@section('title', 'الواجبات')

@section('content')
    <link rel="stylesheet" href="{{ asset('css/homework.css') }}?v=20261025-1">
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
                @php($deadline = \App\Support\AssignmentDeadline::for($assignment))
                <article class="message-card">
                    <span>المادة: {{ $assignment->subject_label }} · {{ $deadline?->setTimezone(\App\Services\LessonPublicationTime::timezone())->format('Y-m-d H:i') ?? 'بدون موعد' }}</span>
                    <h2>{{ $assignment->title }}</h2>
                    @include('shared.homework-instructions', ['assignment' => $assignment])
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
