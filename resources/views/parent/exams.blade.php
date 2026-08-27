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
                @php($score = $exam->grade_published_at ? rtrim(rtrim(number_format((float) $exam->score, 2, '.', ''), '0'), '.') : null)
                @php($total = rtrim(rtrim(number_format((float) $exam->total_score, 2, '.', ''), '0'), '.'))
                <div class="list-row"><div><strong>{{ $exam->title }}</strong><span>{{ $exam->subject }} · {{ \Illuminate\Support\Carbon::parse($exam->starts_at)->format('Y-m-d') }}</span></div><b>{{ $score === null ? 'النتيجة لم تنشر' : $score.' / '.$total }}</b></div>
            @empty
                <p class="muted-line">لا توجد اختبارات سابقة.</p>
            @endforelse
        </section>
    @endif
@endsection
