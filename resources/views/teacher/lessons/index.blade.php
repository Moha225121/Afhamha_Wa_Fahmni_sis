@extends('teacher.layout')
@section('title', 'الدروس')
@section('subtitle', 'اعرض دروسك وتابع الطلاب الذين فتحوها')
@section('actions')<a class="btn primary" href="{{ route('teacher.lessons.create') }}">إضافة درس جديد</a>@endsection
@section('content')
<link rel="stylesheet" href="{{ asset('css/teacher-lessons.css') }}?v=20261013-1">
<section class="lesson-status-tabs" aria-label="تصفية الدروس حسب الحالة">
    @foreach(['published' => 'منشورة', 'scheduled' => 'مجدولة', 'draft' => 'مسودات', 'cancelled' => 'ملغاة'] as $status => $label)
        <a href="{{ route('teacher.lessons.index', array_filter(array_merge($filters, ['status' => $status]))) }}"
           class="lesson-status-tab {{ ($filters['status'] ?? '') === $status ? 'is-active' : '' }}"
           @if(($filters['status'] ?? '') === $status) aria-current="page" @endif>
            <strong>{{ $lessonSummary[$status] }}</strong><span>{{ $label }}</span>
        </a>
    @endforeach
</section>
<details class="lesson-list-filters" @if(! empty($filters['classroom_id']) || ! empty($filters['published_at'])) open @endif>
    <summary>تصفية حسب الصف أو التاريخ</summary>
    <form class="filters" method="get" action="{{ route('teacher.lessons.index') }}">
        <label><span class="label-head">الصف</span><select name="classroom_id"><option value="">كل الصفوف</option>@foreach($classrooms as $classroom)<option value="{{ $classroom->id }}" @selected(($filters['classroom_id'] ?? '') == $classroom->id)>{{ $classroom->name }} {{ $classroom->section }}</option>@endforeach</select></label>
        <label><span class="label-head">تاريخ النشر</span><input type="date" name="published_at" value="{{ $filters['published_at'] ?? '' }}"></label>
        <label><span class="label-head">الحالة</span><select name="status"><option value="">كل الحالات</option><option value="draft" @selected(($filters['status'] ?? '') === 'draft')>مسودة</option><option value="scheduled" @selected(($filters['status'] ?? '') === 'scheduled')>مجدول</option><option value="published" @selected(($filters['status'] ?? '') === 'published')>منشور</option><option value="cancelled" @selected(($filters['status'] ?? '') === 'cancelled')>ملغي</option></select></label>
        <button class="btn primary" type="submit">تصفية</button>
    </form>
</details>
<div class="lesson-list-heading"><p>{{ $lessons->total() }} درس</p>@if(array_filter($filters))<a href="{{ route('teacher.lessons.index') }}">عرض كل الدروس</a>@endif</div>
<div class="teacher-lesson-grid">
@forelse($lessons as $lesson)
    @php
        $isPassed = $lesson->published_at?->isPast();
        $isScheduled = $lesson->status === 'published' && $lesson->published_at?->isFuture();
        $statusLabel = $lesson->status === 'cancelled' ? 'ملغي' : ($lesson->status === 'published' ? ($isScheduled ? 'مجدول' : 'منشور') : 'مسودة');
        $statusClass = $lesson->status === 'cancelled' ? 'status-cancelled' : ($isScheduled ? 'status-scheduled' : ($lesson->status === 'published' ? 'active' : 'inactive'));
    @endphp
    <article class="teacher-lesson-card" aria-labelledby="lesson-title-{{ $lesson->id }}">
        <div class="teacher-lesson-card__header"><span class="teacher-lesson-subject">{{ $lesson->subject?->name }}</span><span class="badge {{ $statusClass }}">{{ $statusLabel }}</span></div>
        <h2 id="lesson-title-{{ $lesson->id }}"><a href="{{ route('teacher.lessons.show', $lesson) }}">{{ $lesson->title }}</a></h2>
        @if($lesson->unit)<p class="teacher-lesson-unit">{{ $lesson->unit->title }}</p>@endif
        @include('teacher.lessons.audience', ['audienceLesson' => $lesson])
        @if(trim(strip_tags($lesson->content ?? '')) !== '')<p class="teacher-lesson-excerpt">{{ \Illuminate\Support\Str::limit(trim(strip_tags($lesson->content)), 140) }}</p>@endif
        <div class="teacher-lesson-card__meta">
            <span>{{ $lesson->attachments->count() }} مرفقات</span>
            @if($lesson->published_at)<span>{{ $isScheduled ? 'موعد النشر' : 'تاريخ النشر' }}: <bdi>{{ $lesson->published_at->copy()->timezone($publicationTimezone)->format('Y-m-d H:i') }}</bdi></span>@endif
        </div>
        <a class="teacher-lesson-readership" href="{{ route('teacher.lessons.views', $lesson) }}" aria-label="عرض {{ $lesson->views_count }} طلاب فتحوا درس {{ $lesson->title }}">
            <span><strong>{{ $lesson->views_count }}</strong> طلاب فتحوا الدرس</span><b>عرض الطلاب <span aria-hidden="true">←</span></b>
        </a>
        <div class="teacher-lesson-card__actions">
            <a class="btn primary" href="{{ route('teacher.lessons.show', $lesson) }}">عرض الدرس</a>
            <a class="btn secondary" href="{{ route('teacher.lessons.edit', $lesson) }}">{{ $lesson->status === 'draft' ? 'متابعة الإعداد' : 'تعديل' }}</a>
            <details class="teacher-lesson-more"><summary aria-label="خيارات درس {{ $lesson->title }}">المزيد</summary><div>
                @if($lesson->status !== 'cancelled' && $isPassed)<form method="post" action="{{ route('teacher.lessons.cancel', $lesson) }}">@csrf @method('patch')<button type="submit" data-confirm="هل أنت متأكد من إلغاء هذا الدرس؟">إلغاء الدرس</button></form>@endif
                <form method="post" action="{{ route('teacher.lessons.destroy', $lesson) }}">@csrf @method('delete')<button class="teacher-lesson-delete" type="submit" data-confirm="هل أنت متأكد من حذف الدرس؟">حذف الدرس</button></form>
            </div></details>
        </div>
    </article>
@empty
    <div class="empty teacher-lesson-empty">{{ array_filter($filters) ? 'لا توجد دروس تطابق التصفية المحددة.' : 'لم تضف أي درس حتى الآن.' }}<br><a class="btn primary" href="{{ route('teacher.lessons.create') }}">إضافة درس جديد</a></div>
@endforelse
</div>
{{ $lessons->links() }}
@endsection
