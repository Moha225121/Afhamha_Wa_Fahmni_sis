@extends('student.layout')
@section('title','الجدول الدراسي')
@section('page-class', 'student-schedule-page')
@push('page-styles')
    <link rel="stylesheet" href="{{ asset('css/student-schedule.css') }}?v=20261015-2">
@endpush
@section('content')
@php
    $dayNames = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
    $periodNames = ['الأولى', 'الثانية', 'الثالثة', 'الرابعة', 'الخامسة', 'السادسة', 'السابعة', 'الثامنة', 'التاسعة', 'العاشرة', 'الحادية عشرة', 'الثانية عشرة'];
    // Time windows define presentation columns; no official period numbers are stored.
    $periodKey = fn ($item) => substr($item->starts_at, 0, 5).'-'.substr($item->ends_at, 0, 5);
    $periods = $schedule->sortBy($periodKey)->unique($periodKey)->values();
    $scheduleByDay = $schedule->groupBy(fn ($item) => (int) $item->day_of_week)->sortKeys();
    $visibleDays = collect(range(0, 4))->merge($scheduleByDay->keys())->unique()->sort()->values();
@endphp
<section class="page-title schedule-page-title">
    <div>
        <h1>الجدول الدراسي</h1>
        <p>أيامك الدراسية وحصصك الأسبوعية في نظرة واحدة.</p>
    </div>
    @if($student->classroom?->name)<span class="classroom-badge">{{ $student->classroom->name }}</span>@endif
</section>

<section class="schedule-content" aria-label="الجدول الدراسي">
    @if($schedule->isNotEmpty())
        <p class="schedule-scroll-hint" id="schedule-scroll-hint">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 8-4 4 4 4M3 12h18m-4-4 4 4-4 4"/></svg>
            يمكنك تمرير الجدول لعرض باقي الحصص.
        </p>
        <div class="schedule-table-scroll" role="region" aria-label="الجدول الأسبوعي" aria-describedby="schedule-scroll-hint" tabindex="0">
            <table class="schedule-timetable" style="--period-count: {{ $periods->count() }}">
                <caption class="visually-hidden">الجدول الدراسي الأسبوعي، الأيام في الصفوف والحصص مرتبة حسب أوقاتها.</caption>
                <thead>
                    <tr>
                        <th class="timetable-day-column" scope="col">اليوم</th>
                        @foreach($periods as $period)
                            <th scope="col">
                                <span class="timetable-period-name">الحصة {{ $periodNames[$loop->index] ?? ($loop->index + 1) }}</span>
                                <span class="timetable-period-time">من <bdi dir="ltr">{{ substr($period->starts_at, 0, 5) }}</bdi> إلى <bdi dir="ltr">{{ substr($period->ends_at, 0, 5) }}</bdi></span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($visibleDays as $day)
                        @php($lessonsByPeriod = $scheduleByDay->get($day, collect())->groupBy($periodKey))
                        <tr>
                            <th class="timetable-day-column" scope="row">{{ $dayNames[$day] ?? $day }}</th>
                            @foreach($periods as $period)
                                <td>
                                    @forelse($lessonsByPeriod->get($periodKey($period), collect()) as $item)
                                        <div class="timetable-lesson" data-schedule-id="{{ $item->id }}">
                                            <strong class="timetable-subject">{{ $item->subject }}</strong>
                                            <span class="timetable-teacher"><span class="visually-hidden">المعلم: </span>{{ $item->teacher }}</span>
                                            @if($item->room)
                                                <span class="timetable-room"><span>المكان</span> {{ $item->room }}</span>
                                            @endif
                                        </div>
                                    @empty
                                        <span class="timetable-empty-cell">لا توجد حصة</span>
                                    @endforelse
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="schedule-time-note">الحصص مرتبة حسب أوقات الجدول المسجلة.</p>
    @else
        <div class="schedule-empty"><p>لا توجد حصص مجدولة حالياً</p></div>
    @endif
</section>
@endsection
