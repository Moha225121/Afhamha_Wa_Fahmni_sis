@extends('parent.layout')

@section('title', $student->user->name)

@section('content')
    <section class="student-profile">
        <span class="large-avatar">{{ mb_substr($student->user->name, 0, 1) }}</span>
        <h1>{{ $student->user->name }}</h1>
        <p>{{ $student->student_number }}</p>
    </section>

    <section class="info-list">
        <div>
            <span>الصف</span>
            <strong>{{ $student->classroom?->name ?? 'بدون صف' }}</strong>
        </div>
        <div>
            <span>الشعبة</span>
            <strong>{{ $student->classroom?->section ?? '-' }}</strong>
        </div>
        <div>
            <span>السنة الدراسية</span>
            <strong>{{ $student->classroom?->academicYear?->name ?? '-' }}</strong>
        </div>
        <div>
            <span>الحالة</span>
            <strong>{{ $student->status }}</strong>
        </div>
    </section>

    <section class="metrics-grid">
        <article class="metric">
            <span>حضور مسجل</span>
            <strong>{{ $summary['attendance_total'] }}</strong>
        </article>
        <article class="metric">
            <span>حاضر</span>
            <strong>{{ $summary['present'] }}</strong>
        </article>
        <article class="metric">
            <span>غياب</span>
            <strong>{{ $summary['absent'] }}</strong>
        </article>
        <article class="metric">
            <span>تأخير</span>
            <strong>{{ $summary['late'] }}</strong>
        </article>
    </section>

    <section class="list-section">
        <div class="section-title">
            <h2>آخر حضور</h2>
        </div>
        @if($latestAttendance)
            <div class="list-row">
                <div>
                    <strong>{{ $latestAttendance->date }}</strong>
                    <span>{{ $latestAttendance->notes ?? 'بدون ملاحظات' }}</span>
                </div>
                <b>{{ $latestAttendance->status }}</b>
            </div>
        @else
            <p class="muted-line">لا يوجد حضور مسجل لهذا الطالب.</p>
        @endif
    </section>

    <section class="list-section">
        <div class="section-title">
            <h2>آخر النتائج</h2>
            <a href="{{ route('parent.results', ['student' => $student->id]) }}">الكل</a>
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
@endsection
