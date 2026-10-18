@push('styles')
    <link rel="stylesheet" href="{{ asset('css/parent-academics.css') }}?v=20261016-1">
@endpush

<form method="get" action="{{ url()->current() }}" class="academic-filter" aria-label="تصفية حسب المادة">
    @if($selectedStudent)<input type="hidden" name="student" value="{{ $selectedStudent->id }}">@endif
    <div class="academic-filter-field">
        <label for="academic-subject">المادة الدراسية</label>
        <select id="academic-subject" name="subject" data-native-select>
            <option value="">كل المواد</option>
            @if($selectedSubjectId && !$subjects->has($selectedSubjectId))
                <option value="{{ $selectedSubjectId }}" selected>مادة غير متاحة</option>
            @endif
            @foreach($subjects as $subjectId => $subjectName)
                <option value="{{ $subjectId }}" @selected($selectedSubjectId === (int) $subjectId)>{{ $subjectName }}</option>
            @endforeach
        </select>
    </div>
    <button type="submit">عرض</button>
    @if($selectedSubjectId)
        <a class="academic-filter-reset" href="{{ url()->current().'?'.http_build_query(['student' => $selectedStudent?->id]) }}">عرض كل المواد</a>
    @endif
</form>
<p class="academic-filter-summary">المادة: <strong>{{ $selectedSubjectId ? $subjects->get($selectedSubjectId, 'مادة غير متاحة') : 'كل المواد' }}</strong></p>
