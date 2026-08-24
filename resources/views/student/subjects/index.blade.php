@extends('student.layout')

@section('title', 'المواد الدراسية')

@section('content')
    <section class="page-title">
        <p>{{ $student->classroom?->name ?? 'بدون صف' }}</p>
        <h1>المواد الدراسية</h1>
    </section>

    <section class="list-section">
        @forelse($subjects as $subject)
            <a class="list-row" href="{{ route('student.subjects.show', $subject) }}">
                <div>
                    <strong>{{ $subject->name }}</strong>
                    <span>{{ $subject->code }}{{ $subject->stage ? ' · '.$subject->stage : '' }}</span>
                    @if($subject->description)
                        <span>{{ $subject->description }}</span>
                    @endif
                </div>
                <b>‹</b>
            </a>
        @empty
            <div class="empty-state">
                <h2>لا توجد مواد متاحة</h2>
                <p>لم تُربط مواد نشطة بصفك حتى الآن.</p>
            </div>
        @endforelse
    </section>
@endsection
