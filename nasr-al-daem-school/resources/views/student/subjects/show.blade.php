@extends('student.layout')

@section('title', $subject->name)

@section('content')
    <section class="page-title">
        <p>{{ $subject->code }} · {{ $student->classroom?->name }}</p>
        <h1>{{ $subject->name }}</h1>
    </section>

    @if($subject->description)
        <section class="list-section">
            <p class="content-copy">{{ $subject->description }}</p>
        </section>
    @endif

    <div class="page-actions">
        <a class="primary-link" href="{{ route('student.lessons.index', $subject) }}">عرض كل الدروس</a>
        <a class="secondary-link" href="{{ route('student.library.index', ['subject_id' => $subject->id]) }}">موارد المادة</a>
    </div>

    @forelse($subject->units as $unit)
        <section class="list-section">
            <div class="section-title">
                <h2>{{ $unit->title }}</h2>
            </div>
            @if($unit->description)
                <p class="muted-line">{{ $unit->description }}</p>
            @endif
            @forelse($unit->lessons as $lesson)
                <a class="list-row" href="{{ route('student.lessons.show', [$subject, $lesson]) }}">
                    <div>
                        <strong>{{ $lesson->title }}</strong>
                        <span>{{ $lesson->published_at->format('Y-m-d') }}</span>
                    </div>
                    <b>‹</b>
                </a>
            @empty
                <p class="muted-line">لا توجد دروس منشورة في هذه الوحدة.</p>
            @endforelse
        </section>
    @empty
        <section class="empty-state">
            <h2>لا توجد وحدات منشورة</h2>
            <p>ستظهر الوحدات والدروس هنا عند توفر المحتوى.</p>
        </section>
    @endforelse

    @if($unassignedLessons->isNotEmpty())
        <section class="list-section">
            <div class="section-title"><h2>دروس أخرى</h2></div>
            @foreach($unassignedLessons as $lesson)
                <a class="list-row" href="{{ route('student.lessons.show', [$subject, $lesson]) }}">
                    <div>
                        <strong>{{ $lesson->title }}</strong>
                        <span>{{ $lesson->published_at->format('Y-m-d') }}</span>
                    </div>
                    <b>‹</b>
                </a>
            @endforeach
        </section>
    @endif

    <section class="list-section">
        <div class="section-title"><h2>موارد المادة</h2></div>
        @forelse($resources as $resource)
            <a class="list-row" href="{{ route('student.library.download', $resource) }}">
                <div>
                    <strong>{{ $resource->title }}</strong>
                    <span>{{ $resource->category ?: 'مورد تعليمي' }}</span>
                </div>
                <b>تنزيل</b>
            </a>
        @empty
            <p class="muted-line">لا توجد موارد متاحة لهذه المادة.</p>
        @endforelse
    </section>
@endsection
