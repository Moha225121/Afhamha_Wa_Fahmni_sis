<aside class="lesson-quick-nav" aria-label="الوصول السريع للدروس">
    <details open>
        <summary>دروس {{ $subject->name }}</summary>
        <nav aria-label="دروس المادة">
            <a class="lesson-list-back" href="{{ route('student.subjects.index') }}">→ العودة إلى المواد</a>
            @forelse($quickLessons as $quickLesson)
                <a href="{{ route('student.lessons.show', [$subject, $quickLesson]) }}" @if(isset($lesson) && $lesson->id === $quickLesson->id) aria-current="page" @endif>{{ $quickLesson->title }}</a>
            @empty
                <p>لا توجد دروس منشورة.</p>
            @endforelse
        </nav>
    </details>
</aside>
