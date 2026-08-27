@extends('student.layout')

@section('title', 'لوحة الطالب')

@section('content')
    <section class="hero">
        <div class="hero-content">
            <p class="hero-eyebrow"><span aria-hidden="true"></span> يوم دراسي سعيد</p>
            <h1>مرحباً، {{ $student->user->name }}</h1>
            <p class="hero-description">كل ما تحتاجه لمتابعة دروسك وواجباتك ونتائجك في مكان واحد.</p>
            <div class="hero-meta">
                <span>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H11v16H6.5A2.5 2.5 0 0 0 4 21.5zM20 5.5A2.5 2.5 0 0 0 17.5 3H13v16h4.5a2.5 2.5 0 0 1 2.5 2.5z"/></svg>
                    {{ $student->classroom?->name ?? 'بدون صف' }}
                </span>
            </div>
        </div>
        <div class="hero-visual" aria-hidden="true">
            <span class="hero-visual-card card-one"><svg viewBox="0 0 24 24"><path d="m9 12 2 2 4-5M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18"/></svg></span>
            <span class="hero-visual-card card-two"><svg viewBox="0 0 24 24"><path d="M5 4h14v16H5zM8 8h8M8 12h8M8 16h5"/></svg></span>
            <span class="hero-visual-card card-three"><svg viewBox="0 0 24 24"><path d="M4 19V8l8-4 8 4v11M8 19v-5h8v5"/></svg></span>
        </div>
    </section>

    <section class="academic-services" aria-labelledby="academic-services-title">
        <div class="section-heading">
            <div>
                <span>اختصارات سريعة</span>
                <h2 id="academic-services-title">الخدمات الأكاديمية</h2>
            </div>
            <p>انتقل مباشرةً إلى أهم أدواتك الدراسية.</p>
        </div>
        <div class="service-grid">
            <a class="service-card service-card--assignments" href="{{ route('student.assignments.index') }}">
                <span class="service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 4h8M9 3h6v3H9zM6 5H4v16h16V5h-2M8 11h8M8 15h5"/></svg></span>
                <span class="service-copy"><strong>الواجبات والتسليم</strong><small>تابع المطلوب وارفع ملفاتك</small></span>
                <span class="service-arrow" aria-hidden="true">‹</span>
            </a>
            <a class="service-card service-card--exams" href="{{ route('student.exams.index') }}">
                <span class="service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18M12 7v5l3 2"/></svg></span>
                <span class="service-copy"><strong>الاختبارات الإلكترونية</strong><small>الاختبارات المتاحة والنتائج</small></span>
                <span class="service-arrow" aria-hidden="true">‹</span>
            </a>
            <a class="service-card service-card--attendance" href="{{ route('student.attendance') }}">
                <span class="service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 3v3M17 3v3M4 8h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1M8 13l2 2 5-5"/></svg></span>
                <span class="service-copy"><strong>سجل الحضور</strong><small>راجع حضورك وغيابك</small></span>
                <span class="service-arrow" aria-hidden="true">‹</span>
            </a>
            <a class="service-card service-card--schedule" href="{{ route('student.schedule') }}">
                <span class="service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5h16v15H4zM8 3v4M16 3v4M4 9h16M8 13h3M13 13h3M8 17h3"/></svg></span>
                <span class="service-copy"><strong>الجدول الدراسي</strong><small>مواعيد حصصك الأسبوعية</small></span>
                <span class="service-arrow" aria-hidden="true">‹</span>
            </a>
            <a class="service-card service-card--notifications" href="{{ route('student.notifications') }}">
                <span class="service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg></span>
                <span class="service-copy"><strong>الإشعارات</strong><small>ابقَ على اطلاع بكل جديد</small></span>
                <span class="service-arrow" aria-hidden="true">‹</span>
            </a>
        </div>
    </section>

    <section class="metrics-grid dashboard-metrics" aria-label="ملخص الأداء">
        <article class="metric metric--attendance">
            <span class="metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 3v3M17 3v3M4 8h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1M8 13l2 2 5-5"/></svg></span>
            <div><span>حضور مسجل</span><strong>{{ $summary['attendance_total'] }}</strong></div>
        </article>
        <article class="metric metric--results">
            <span class="metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 20V10M12 20V4M19 20v-7"/></svg></span>
            <div><span>نتائج منشورة</span><strong>{{ $summary['published_grades'] }}</strong></div>
        </article>
        <article class="metric metric--average">
            <span class="metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/></svg></span>
            <div><span>المتوسط</span><strong>{{ $summary['average_percent'] === null ? '-' : $summary['average_percent'].'%' }}</strong></div>
        </article>
        <article class="metric metric--absence">
            <span class="metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/></svg></span>
            <div><span>غياب</span><strong>{{ $summary['absent'] }}</strong></div>
        </article>
    </section>

    <div class="dashboard-panels">
        <section class="list-section">
            <div class="section-title">
                <div><span class="section-kicker">أداؤك</span><h2>آخر النتائج</h2></div>
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
                    <b class="numeric-value">{{ $score }} / {{ $total }}</b>
                </div>
            @empty
                <p class="muted-line">لا توجد نتائج منشورة.</p>
            @endforelse
        </section>

        <section class="list-section">
            <div class="section-title">
                <div><span class="section-kicker">موادك</span><h2>مواد صفي</h2></div>
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
                <div><span class="section-kicker">حصصك</span><h2>جدول اليوم</h2></div>
            </div>
            @forelse($todaysSchedule as $session)
                <div class="list-row">
                    <div>
                        <strong>{{ $session->subject }}</strong>
                        <span>{{ $session->teacher }}{{ $session->room ? ' · '.$session->room : '' }}</span>
                    </div>
                    <b class="numeric-value">{{ substr($session->starts_at, 0, 5) }} – {{ substr($session->ends_at, 0, 5) }}</b>
                </div>
            @empty
                <p class="muted-line">لا توجد حصص مسجلة لهذا اليوم.</p>
            @endforelse
        </section>

        <section class="list-section">
            <div class="section-title">
                <div><span class="section-kicker">آخر التحديثات</span><h2>التنبيهات</h2></div>
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
    </div>
@endsection
