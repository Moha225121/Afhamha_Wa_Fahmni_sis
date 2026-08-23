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
