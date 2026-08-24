@extends('student.layout')

@section('title', 'لوحة الطالب')

@section('content')
    <section class="hero">
        <p>متابعة الطالب</p>
        <h1>مرحباً {{ $student->user->name }}</h1>
        <span>{{ $student->classroom?->name ?? 'بدون صف' }}</span>
    </section>

    <section class="list-section">
        <div class="section-title"><h2>الخدمات الأكاديمية</h2></div>
        <a class="message-row" href="{{ route('student.assignments.index') }}"><strong>الواجبات والتسليم</strong><span>عرض</span></a>
        <a class="message-row" href="{{ route('student.exams.index') }}"><strong>الاختبارات الإلكترونية</strong><span>عرض</span></a>
        <a class="message-row" href="{{ route('student.attendance') }}"><strong>سجل الحضور</strong><span>عرض</span></a>
        <a class="message-row" href="{{ route('student.schedule') }}"><strong>الجدول الدراسي</strong><span>عرض</span></a>
        <a class="message-row" href="{{ route('student.notifications') }}"><strong>الإشعارات</strong><span>عرض</span></a>
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
