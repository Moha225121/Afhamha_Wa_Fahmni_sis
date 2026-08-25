@extends('student.layout')

@section('title', 'ملف الطالب')

@section('content')
    <section class="student-profile">
        <span class="large-avatar">{{ mb_substr($student->user->name, 0, 1) }}</span>
        <h1>{{ $student->user->name }}</h1>
        <p>{{ $student->student_number }}</p>
    </section>

    <section class="info-list">
        <div>
            <span>البريد</span>
            <strong>{{ $student->user->email }}</strong>
        </div>
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
    </section>

    <section class="more-list" aria-label="روابط الطالب الأخرى">
        <a href="{{ route('student.results') }}"><span aria-hidden="true">◌</span>النتائج</a>
        <a href="{{ route('student.messages') }}"><span aria-hidden="true">✉</span>الإعلانات والرسائل</a>
    </section>
@endsection
