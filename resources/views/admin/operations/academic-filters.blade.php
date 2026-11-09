<form class="filters" method="get">
    @if($filterType === 'exams')
        <input name="q" value="{{ request('q') }}" placeholder="عنوان الاختبار" aria-label="عنوان الاختبار">
    @else
        <select name="academic_year_id" aria-label="السنة الدراسية"><option value="">كل السنوات</option>@foreach($years as $year)<option value="{{ $year->id }}" @selected(request('academic_year_id') == $year->id)>{{ $year->name }}</option>@endforeach</select>
    @endif
    <select name="classroom_id" aria-label="الصف والشعبة"><option value="">كل الصفوف والشعب</option>@foreach($classrooms as $room)<option value="{{ $room->id }}" @selected(request('classroom_id') == $room->id)>{{ $room->name }} {{ $room->section }} — {{ $room->academicYear?->name }}</option>@endforeach</select>
    <select name="subject_id" aria-label="المادة"><option value="">كل المواد</option>@foreach($subjects as $subject)<option value="{{ $subject->id }}" @selected(request('subject_id') == $subject->id)>{{ $subject->name }} {{ $subject->code }}</option>@endforeach</select>
    @if($filterType === 'exams')
        <select name="teacher_id" aria-label="المعلم"><option value="">كل المعلمين</option>@foreach($teachers as $teacher)<option value="{{ $teacher->id }}" @selected(request('teacher_id') == $teacher->id)>{{ $teacher->user?->name }}</option>@endforeach</select>
        <select name="status" aria-label="حالة الاختبار"><option value="">كل الحالات</option>@foreach(['draft'=>'مسودة','scheduled'=>'مجدول','published'=>'منشور','completed'=>'مكتمل','cancelled'=>'ملغى'] as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select>
    @endif
    <button class="btn secondary">تصفية</button>
    <a class="btn secondary" href="{{ route('admin.'.$filterType.'.index') }}">إعادة الضبط</a>
</form>
