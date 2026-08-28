@extends('teacher.layout')
@section('title', 'صفوفي')
@section('subtitle', 'نظرة سريعة على الصفوف المسندة إليك وأداء طلابك')
@section('content')
<section class="teacher-class-grid">
@forelse($classrooms as $classroom)
<a class="teacher-class-card" href="{{ route('teacher.classes.show', $classroom) }}" aria-label="عرض تفاصيل {{ $classroom->name }}">
<span class="teacher-class-card__header"><small>الفصل {{ $classroom->section ?: 'الدراسي' }}</small><strong>{{ $classroom->name }}</strong><span class="teacher-class-card__header-subjects">@forelse($classroom->assignment_labels as $label)<span>{{ $label }}</span>@empty<span>لا توجد مادة</span>@endforelse</span><i aria-hidden="true">{{ mb_substr($classroom->name, 0, 1) }}</i></span>
<span class="teacher-class-card__body"><span class="teacher-class-card__summary-line"><span class="teacher-card-metric"><small>الطلاب المسجلون</small><b>{{ $classroom->students_count }}</b></span><span class="teacher-card-metric teacher-card-metric--score"><small>متوسط التحصيل</small><b>{{ $classroom->average_grade !== null ? number_format($classroom->average_grade, 1).'%' : '-' }}</b></span></span><span class="teacher-class-card__progress" role="progressbar" aria-label="متوسط تحصيل الصف" aria-valuenow="{{ min(100, max(0, (float) ($classroom->average_grade ?? 0))) }}" aria-valuemin="0" aria-valuemax="100"><span style="width:{{ min(100, max(0, (float) ($classroom->average_grade ?? 0))) }}%"></span></span><span class="teacher-class-card__action">استعراض تفاصيل الصف <b aria-hidden="true">←</b></span></span>
</a>
@empty
<div class="empty">لا توجد صفوف مسندة إلى حسابك.</div>
@endforelse
</section>
@endsection
