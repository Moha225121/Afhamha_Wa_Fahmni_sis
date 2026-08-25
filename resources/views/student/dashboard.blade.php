@extends('student.layout')

@section('title', 'لوحة الطالب')

@section('content')
    <section class="hero">
        <p>متابعة الطالب</p>
        <h1>مرحباً {{ $student->user->name }}</h1>
        <span>{{ $student->classroom?->name ?? 'بدون صف' }}</span>
    </section>

    <section class="metrics-grid">
        <article class="metric">
            <span>حضور مسجل</span>
            <strong>{{ $summary['attendance_total'] }}</strong>
        </article>
        <article class="metric">
            <span>نتائج منشورة</span>
            <strong>{{ $summary['published_grades'] }}</strong>
        </article>
        <article class="metric">
            <span>المتوسط</span>
            <strong>{{ $summary['average_percent'] === null ? '-' : $summary['average_percent'].'%' }}</strong>
        </article>
        <article class="metric">
            <span>غياب</span>
            <strong>{{ $summary['absent'] }}</strong>
        </article>
    </section>

    <section class="list-section">
        <div class="section-title">
            <h2>آخر النتائج</h2>
            <a href="{{ route('student.results') }}">الكل</a>
        </div>
        @forelse($recentGrades as $grade)
            @php($score = rtrim(rtrim(number_format((float) $grade->score, 2, '.', ''), '0'), '.'))
            @php($total = rtrim(rtrim(number_format((float) $grade->total_score, 2, '.', ''), '0'), '.'))
            <div class="list-row">
                <div>
                    <strong>{{ $grade->subject }}</strong>
                    <span>{{ $grade->title }}</span>
                </div>
                <b>{{ $score }} / {{ $total }}</b>
            </div>
        @empty
            <p class="muted-line">لا توجد نتائج منشورة.</p>
        @endforelse
    </section>

    <section class="list-section">
        <div class="section-title">
            <h2>الاختبارات القادمة</h2>
        </div>
        @forelse($upcomingExams as $exam)
            <div class="list-row">
                <div>
                    <strong>{{ $exam->subject }}</strong>
                    <span>{{ $exam->title }}</span>
                </div>
                <b>{{ \Illuminate\Support\Carbon::parse($exam->starts_at)->format('Y-m-d') }}</b>
            </div>
        @empty
            <p class="muted-line">لا توجد اختبارات مجدولة حاليًا.</p>
        @endforelse
    </section>

    <section class="list-section">
        <div class="section-title">
            <h2>الرسائل</h2>
            <a href="{{ route('student.messages') }}">الكل</a>
        </div>
        @forelse($announcements as $announcement)
            <a class="message-row" href="{{ route('student.messages') }}">
                <strong>{{ $announcement->title }}</strong>
                <span>{{ $announcement->published_at?->format('Y-m-d') ?? $announcement->created_at?->format('Y-m-d') }}</span>
            </a>
        @empty
            <p class="muted-line">لا توجد رسائل منشورة حالياً.</p>
        @endforelse
    </section>
@endsection
