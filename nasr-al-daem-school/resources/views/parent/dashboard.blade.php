@extends('parent.layout')

@section('title', 'لوحة ولي الأمر')

@section('content')
    <section class="hero">
        <p>متابعة اليوم</p>
        <h1>مرحباً {{ strtok(auth()->user()->name, ' ') ?: auth()->user()->name }}</h1>
        <span>{{ now()->format('Y-m-d') }}</span>
    </section>

    @if($children->isEmpty())
        <section class="empty-state">
            <h2>لا يوجد أبناء مرتبطون</h2>
            <p>يظهر أبناء ولي الأمر هنا بعد ربطهم من إدارة المدرسة.</p>
        </section>
    @else
        @include('parent.partials.child-switcher')

        <section class="student-focus">
            <div>
                <p>الطالب الحالي</p>
                <h2>{{ $selectedStudent->user->name }}</h2>
                <span>{{ $selectedStudent->classroom?->name ?? 'بدون صف' }} @if($selectedStudent->classroom?->section) - {{ $selectedStudent->classroom->section }} @endif</span>
            </div>
            <a href="{{ route('parent.children.show', $selectedStudent) }}">عرض</a>
        </section>

        <section class="metrics-grid">
            <article class="metric">
                <span>الأبناء</span>
                <strong>{{ $children->count() }}</strong>
            </article>
            <article class="metric">
                <span>الحضور</span>
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
        </section>

        <section class="list-section">
            <div class="section-title">
                <h2>آخر النتائج</h2>
                <a href="{{ route('parent.results', ['student' => $selectedStudent->id]) }}">الكل</a>
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
                <p class="muted-line">لا توجد نتائج منشورة لهذا الطالب.</p>
            @endforelse
        </section>

        <section class="quick-links">
            <a href="{{ route('parent.attendance', ['student' => $selectedStudent->id]) }}">الحضور</a>
            <a href="{{ route('parent.assignments', ['student' => $selectedStudent->id]) }}">الواجبات</a>
            <a href="{{ route('parent.exams', ['student' => $selectedStudent->id]) }}">الاختبارات</a>
            <a href="{{ route('parent.notifications') }}">الإشعارات</a>
        </section>

        <section class="list-section">
            <div class="section-title">
                <h2>الرسائل</h2>
                <a href="{{ route('parent.messages', ['student' => $selectedStudent->id]) }}">الكل</a>
            </div>
            @forelse($announcements as $announcement)
                <a class="message-row" href="{{ route('parent.messages') }}">
                    <strong>{{ $announcement->title }}</strong>
                    <span>{{ $announcement->published_at?->format('Y-m-d') ?? $announcement->created_at?->format('Y-m-d') }}</span>
                </a>
            @empty
                <p class="muted-line">لا توجد رسائل منشورة حالياً.</p>
            @endforelse
        </section>
    @endif
@endsection
