@extends('student.layout')

@section('title', 'دروس '.$subject->name)

@section('content')
    <nav class="breadcrumbs" aria-label="مسار التنقل">
        <a href="{{ route('student.subjects.index') }}">المواد</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('student.subjects.show', $subject) }}">{{ $subject->name }}</a>
        <span aria-hidden="true">/</span>
        <span>الدروس</span>
    </nav>

    <section class="page-title">
        <p>{{ $student->classroom?->name }}</p>
        <h1>دروس {{ $subject->name }}</h1>
    </section>

    @forelse($subject->units as $unit)
        <section class="list-section">
            <div class="section-title"><h2>{{ $unit->title }}</h2></div>
            @if($unit->description)
                <p class="muted-line">{{ $unit->description }}</p>
            @endif
            @forelse($unit->lessons as $lesson)
                <a class="list-row" href="{{ route('student.lessons.show', [$subject, $lesson]) }}">
                    <div>
                        <strong>{{ $lesson->title }}</strong>
                        <span>منشور في {{ $lesson->published_at->format('Y-m-d') }}</span>
                    </div>
                    <b>‹</b>
                </a>
            @empty
                <p class="muted-line">لا توجد دروس منشورة في هذه الوحدة.</p>
            @endforelse
        </section>
    @empty
        @if($unassignedLessons->isEmpty())
            <section class="empty-state">
                <h2>لا توجد دروس منشورة</h2>
                <p>سيظهر المحتوى هنا عند نشره.</p>
            </section>
        @endif
    @endforelse

    @if($unassignedLessons->isNotEmpty())
        <section class="list-section">
            <div class="section-title"><h2>دروس أخرى</h2></div>
            @foreach($unassignedLessons as $lesson)
                <a class="list-row" href="{{ route('student.lessons.show', [$subject, $lesson]) }}">
                    <div>
                        <strong>{{ $lesson->title }}</strong>
                        <span>منشور في {{ $lesson->published_at->format('Y-m-d') }}</span>
                    </div>
                    <b>‹</b>
                </a>
            @endforeach
        </section>
    @endif
@endsection
