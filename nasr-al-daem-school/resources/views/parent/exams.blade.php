@extends('parent.layout')

@section('title', 'الاختبارات')

@section('content')
    <section class="page-title"><p>متابعة فقط</p><h1>الاختبارات والنتائج</h1></section>

    @if($children->isEmpty())
        <section class="empty-state"><h2>لا يوجد أبناء مرتبطون</h2><p>لا يمكن عرض الاختبارات قبل ربط طالب بحساب ولي الأمر.</p></section>
    @else
        @include('parent.partials.child-switcher')
        <section class="list-section">
            <div class="section-title"><h2>الاختبارات القادمة</h2></div>
            @forelse($upcomingExams as $exam)
                <div class="list-row"><div><strong>{{ $exam->title }}</strong><span>{{ $exam->subject }} · {{ \Illuminate\Support\Carbon::parse($exam->starts_at)->format('Y-m-d H:i') }}</span></div><b>{{ $exam->duration_minutes }} د</b></div>
            @empty
                <p class="muted-line">لا توجد اختبارات قادمة.</p>
            @endforelse
        </section>
        <section class="list-section">
            <div class="section-title"><h2>الاختبارات السابقة</h2></div>
            @forelse($previousExams as $exam)
                @php
                    $hasManualGrade = (bool) $exam->grade_published_at;
                    $hasAutomaticGrade = !$hasManualGrade && $exam->automatic_status === 'submitted' && $exam->automatic_percentage !== null;
                    $score = $hasManualGrade
                        ? rtrim(rtrim(number_format((float) $exam->score, 2, '.', ''), '0'), '.')
                        : ($hasAutomaticGrade ? rtrim(rtrim(number_format((float) $exam->automatic_score, 2, '.', ''), '0'), '.') : null);
                    $maximum = $hasAutomaticGrade ? $exam->automatic_maximum_score : $exam->total_score;
                    $total = rtrim(rtrim(number_format((float) $maximum, 2, '.', ''), '0'), '.');
                    $resultLabel = $score !== null
                        ? $score.' / '.$total
                        : ($exam->automatic_status === 'pending_review' ? 'بانتظار المراجعة' : 'النتيجة لم تنشر');
                @endphp
                <div class="list-row"><div><strong>{{ $exam->title }}</strong><span>{{ $exam->subject }} · {{ \Illuminate\Support\Carbon::parse($exam->starts_at)->format('Y-m-d') }}</span></div><b>{{ $resultLabel }}</b></div>
            @empty
                <p class="muted-line">لا توجد اختبارات سابقة.</p>
            @endforelse
        </section>
    @endif
@endsection
