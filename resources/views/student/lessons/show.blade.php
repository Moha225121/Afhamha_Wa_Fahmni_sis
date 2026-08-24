@extends('student.layout')

@section('title', $lesson->title)

@section('content')
    <nav class="breadcrumbs" aria-label="مسار التنقل">
        <a href="{{ route('student.subjects.index') }}">المواد</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('student.subjects.show', $subject) }}">{{ $subject->name }}</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('student.lessons.index', $subject) }}">الدروس</a>
        <span aria-hidden="true">/</span>
        <span>{{ $lesson->title }}</span>
    </nav>

    <section class="page-title">
        <p>{{ $lesson->unit?->title ?? 'درس مستقل' }} · {{ $subject->name }}</p>
        <h1>{{ $lesson->title }}</h1>
    </section>

    <article class="lesson-content">
        <div class="content-copy">{{ $lesson->content }}</div>
    </article>

    <section class="list-section">
        <div class="section-title"><h2>ملفات الدرس</h2></div>
        @forelse($lesson->attachments as $attachment)
            <a class="list-row" href="{{ route('student.lessons.attachments.download', [$subject, $lesson, $attachment]) }}">
                <div>
                    <strong>{{ $attachment->title }}</strong>
                    <span>{{ $attachment->original_name ?: 'ملف تعليمي' }}</span>
                </div>
                <b>تنزيل</b>
            </a>
        @empty
            <p class="muted-line">لا توجد ملفات مرفقة بهذا الدرس.</p>
        @endforelse
    </section>

    <section class="list-section">
        <div class="section-title"><h2>موارد مرتبطة بالمادة</h2></div>
        @forelse($resources as $resource)
            <a class="list-row" href="{{ route('student.library.download', $resource) }}">
                <div>
                    <strong>{{ $resource->title }}</strong>
                    <span>{{ $resource->category ?: 'مورد تعليمي' }}</span>
                </div>
                <b>تنزيل</b>
            </a>
        @empty
            <p class="muted-line">لا توجد موارد إضافية متاحة.</p>
        @endforelse
    </section>

    <div class="page-actions">
        <a class="primary-link" href="{{ route('student.library.index', ['subject_id' => $subject->id]) }}">فتح المكتبة الرقمية</a>
    </div>
@endsection
