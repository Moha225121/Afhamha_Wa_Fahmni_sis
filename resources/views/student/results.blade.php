@extends('student.layout')

@section('title', 'نتائج الطالب')

@section('content')
    <section class="page-title">
        <p>{{ $student->user->name }}</p>
        <h1>النتائج</h1>
    </section>

    <section class="metrics-grid">
        <article class="metric wide">
            <span>المتوسط</span>
            <strong>{{ $summary['average_percent'] === null ? '-' : $summary['average_percent'].'%' }}</strong>
        </article>
        <article class="metric">
            <span>منشورة</span>
            <strong>{{ $summary['published_grades'] }}</strong>
        </article>
    </section>

    <section class="list-section">
        <div class="section-title"><h2>نتائج الاختبارات الإلكترونية</h2></div>
        @forelse($automaticResults as $result)
            <a class="list-row" href="{{ route('student.exams.result', $result->id) }}">
                <div><strong>{{ $result->subject }}</strong><span>{{ $result->title }} · {{ $result->submitted_at ? \Illuminate\Support\Carbon::parse($result->submitted_at)->format('Y-m-d') : '-' }}</span></div>
                <b>{{ $result->status === 'pending_review' ? 'بانتظار المراجعة' : rtrim(rtrim(number_format((float) $result->score, 2, '.', ''), '0'), '.').' / '.rtrim(rtrim(number_format((float) $result->maximum_score, 2, '.', ''), '0'), '.') }}</b>
            </a>
        @empty
            <p class="muted-line">لا توجد نتائج اختبارات إلكترونية.</p>
        @endforelse
    </section>

    <section class="list-section">
        <div class="section-title"><h2>الدرجات المنشورة</h2></div>
        @forelse($recentGrades as $grade)
            @php($score = rtrim(rtrim(number_format((float) $grade->score, 2, '.', ''), '0'), '.'))
            @php($total = rtrim(rtrim(number_format((float) $grade->total_score, 2, '.', ''), '0'), '.'))
            <div class="list-row">
                <div>
                    <strong>{{ $grade->subject }}</strong>
                    <span>{{ $grade->title }} · {{ $grade->published_at ? \Illuminate\Support\Carbon::parse($grade->published_at)->format('Y-m-d') : '-' }}</span>
                </div>
                <b>{{ $score }} / {{ $total }}</b>
            </div>
        @empty
            <p class="muted-line">لا توجد نتائج منشورة.</p>
        @endforelse
    </section>
@endsection
