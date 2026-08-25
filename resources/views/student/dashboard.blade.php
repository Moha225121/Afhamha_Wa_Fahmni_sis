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
            <h2>مواد صفي</h2>
            <a href="{{ route('student.subjects.index') }}">الكل</a>
        </div>
        @forelse($subjects as $subject)
            <a class="list-row" href="{{ route('student.subjects.show', $subject) }}">
                <div>
                    <strong>{{ $subject->name }}</strong>
                    <span>{{ $subject->code }}{{ $subject->description ? ' · '.$subject->description : '' }}</span>
                </div>
                <b>‹</b>
            </a>
        @empty
            <p class="muted-line">لا توجد مواد نشطة مرتبطة بصفك حاليًا.</p>
        @endforelse
    </section>

    <section class="list-section">
        <div class="section-title">
            <h2>جدول اليوم</h2>
        </div>
        @forelse($todaysSchedule as $session)
            <div class="list-row">
                <div>
                    <strong>{{ $session->subject }}</strong>
                    <span>{{ $session->teacher }}{{ $session->room ? ' · '.$session->room : '' }}</span>
                </div>
                <b>{{ substr($session->starts_at, 0, 5) }} – {{ substr($session->ends_at, 0, 5) }}</b>
            </div>
        @empty
            <p class="muted-line">لا توجد حصص مسجلة لهذا اليوم.</p>
        @endforelse
    </section>

    <section class="list-section">
        <div class="section-title">
            <h2>التنبيهات</h2>
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
