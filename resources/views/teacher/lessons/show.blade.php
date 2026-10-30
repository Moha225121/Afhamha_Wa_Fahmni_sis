@extends('teacher.layout')
@section('title', 'عرض الدرس')
@section('subtitle', 'معاينة محتوى الدرس ومرفقاته')
@section('actions')<a class="btn secondary" href="{{ route('teacher.lessons.index') }}">العودة إلى الدروس</a>@endsection
@section('content')
<link rel="stylesheet" href="{{ asset('css/teacher-lessons.css') }}?v=20261013-1">
@php
    $isScheduled = $lesson->status === 'published' && $lesson->published_at?->isFuture();
    $contentText = trim(html_entity_decode(strip_tags(preg_replace('~<(?:br\s*/?|/(?:p|div|li|h[1-6]))\s*>~i', "\n", $lesson->content ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
@endphp
<article class="teacher-lesson-preview">
    <header class="teacher-lesson-preview__heading">
        <div class="teacher-lesson-card__header"><span class="teacher-lesson-subject">{{ $lesson->subject?->name }}</span><span class="badge {{ $lesson->status === 'cancelled' ? 'status-cancelled' : ($isScheduled ? 'status-scheduled' : ($lesson->status === 'published' ? 'active' : 'inactive')) }}">{{ $lesson->status === 'cancelled' ? 'ملغي' : ($lesson->status === 'published' ? ($isScheduled ? 'مجدول' : 'منشور') : 'مسودة') }}</span></div>
        <h2>{{ $lesson->title }}</h2>
        @if($lesson->unit)<p class="teacher-lesson-unit">{{ $lesson->unit->title }}</p>@endif
        @include('teacher.lessons.audience', ['audienceLesson' => $lesson])
        @if($lesson->published_at)<p class="teacher-lesson-card__meta">{{ $isScheduled ? 'موعد النشر' : 'تاريخ النشر' }}: <bdi>{{ $lesson->published_at->copy()->timezone($publicationTimezone)->format('Y-m-d H:i') }}</bdi></p>@endif
        <div class="teacher-lesson-preview__actions"><a class="btn primary" href="{{ route('teacher.lessons.edit', $lesson) }}">تعديل الدرس</a><a class="btn secondary" href="{{ route('teacher.lessons.views', $lesson) }}">طلاب فتحوا الدرس: {{ $lesson->views_count }}</a></div>
    </header>
    <section class="teacher-lesson-body" aria-labelledby="lesson-content-heading">
        <h3 id="lesson-content-heading">شرح الدرس</h3>
        @if($contentText !== '')<div class="teacher-lesson-copy">{!! nl2br(e($contentText)) !!}</div>@else<p class="muted">لا يوجد شرح نصي مضاف لهذا الدرس.</p>@endif
    </section>
    <section class="teacher-lesson-files" aria-labelledby="lesson-files-heading">
        <h3 id="lesson-files-heading">مرفقات الدرس <span>({{ $lesson->attachments->count() }})</span></h3>
        @forelse($lesson->attachments as $attachment)
            @php($fileUrl = route('teacher.lessons.attachments.download', [$lesson, $attachment]))
            @php($mime = (string) $attachment->mime_type)
            <details class="teacher-lesson-file">
                <summary><strong>{{ $attachment->original_name ?: $attachment->title }}</strong><span>عرض الملف</span></summary>
                <div class="teacher-lesson-file__body">
                    @if(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true))
                        <img class="teacher-lesson-file__image" src="{{ $fileUrl }}" alt="{{ $attachment->title }}" loading="lazy">
                    @elseif($mime === 'video/mp4')
                        <video class="teacher-lesson-file__video" src="{{ $fileUrl }}" controls preload="none"></video>
                    @elseif($mime === 'application/pdf')
                        <iframe class="teacher-lesson-file__pdf" src="{{ $fileUrl }}" title="{{ $attachment->title }}" loading="lazy"></iframe>
                    @else
                        <p class="muted">افتح الملف لعرضه بالتطبيق المناسب.</p>
                    @endif
                    <a class="btn secondary" href="{{ $fileUrl }}" target="_blank" rel="noopener">فتح الملف في نافذة جديدة</a>
                    <a class="btn secondary" href="{{ $fileUrl }}?download=1">تنزيل الملف</a>
                </div>
            </details>
        @empty
            <p class="muted">لا توجد ملفات مرفقة.</p>
        @endforelse
    </section>
</article>
@endsection
