<div class="teacher-lesson-audience" aria-label="الصفوف والشعب">
    @php
        $audienceRooms = $audienceLesson->classrooms->isNotEmpty() ? $audienceLesson->classrooms : collect([$audienceLesson->classroom])->filter();
    @endphp
    @forelse($audienceRooms as $classroom)
        @php
            $branch = \App\Services\TeacherLessonTargets::branchLabel($classroom);
            $className = trim($classroom->name);
            if (in_array($branch, ['A', 'B', 'C'], true) && preg_match('/\s+([ABCabcأابج])$/u', $className, $suffix)
                && mb_strtoupper(strtr($suffix[1], ['أ' => 'A', 'ا' => 'A', 'ب' => 'B', 'ج' => 'C'])) === $branch) {
                $className = trim(preg_replace('/\s+([ABCabcأابج])$/u', '', $className));
            }
        @endphp
        <span>{{ $className }}@if($branch !== '') <bdi>{{ $branch }}</bdi>@endif</span>
    @empty
        <span>جميع الصفوف المتاح لها الدرس</span>
    @endforelse
</div>
