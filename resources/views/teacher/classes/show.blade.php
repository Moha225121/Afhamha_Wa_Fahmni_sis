@extends('teacher.layout')
@section('title', $classroom->name)
@section('subtitle', 'الطلاب والعمليات الأكاديمية للصف')
@section('content')
<section class="class-detail-heading"><div><span class="eyebrow">{{ $classroom->academicYear?->name ?? 'الصف الدراسي' }} · الشعبة {{ $classroom->section ?: 'غير محددة' }}</span></div><a class="btn secondary" href="{{ route('teacher.classes.index') }}">العودة إلى الصفوف</a></section>
<section class="cards class-detail-stats">
<article class="card stat class-detail-stat class-detail-stat--students"><span>عدد الطلاب</span><strong>{{ $classroom->students_count }}</strong><small>طالبًا</small></article>
<article class="card stat class-detail-stat class-detail-stat--attendance"><span>حضور اليوم</span><strong>{{ $attendanceRate }}%</strong><small>{{ $attendanceToday }} حاضرًا</small></article>
<article class="card stat class-detail-stat class-detail-stat--grades"><span>متوسط الصف</span><strong>{{ $averageGrade !== null ? number_format($averageGrade, 1).'%' : '-' }}</strong><small>متوسط الدرجات</small></article>
</section>
<nav class="class-tabs" aria-label="أقسام الصف">
@foreach(['students' => 'الطلاب', 'attendance' => 'الحضور', 'grades' => 'الدرجات', 'assignments' => 'الواجبات'] as $tab => $label)
<a class="{{ $activeTab === $tab ? 'active' : '' }}" href="{{ $tab === 'students' ? route('teacher.classes.show', $classroom) : route('teacher.'.(['attendance' => 'attendance.index', 'grades' => 'grades.index', 'assignments' => 'assignments.index'][$tab]), ['classroom_id' => $classroom->id]) }}">{{ $label }}</a>
@endforeach
</nav>
@if($activeTab !== 'students')
<section class="panel class-tab-panel"><h2>{{ ['attendance' => 'حضور الصف', 'grades' => 'درجات الصف', 'assignments' => 'واجبات الصف'][$activeTab] }}</h2><p>استخدم الوحدة المرتبطة لعرض وتعديل بيانات هذا الصف.</p>
<a class="btn primary" href="{{ route('teacher.'.(['attendance' => 'attendance.index', 'grades' => 'grades.index', 'assignments' => 'assignments.index'][$activeTab]), ['classroom_id' => $classroom->id]) }}">فتح {{ ['attendance' => 'الحضور', 'grades' => 'الدرجات', 'assignments' => 'الواجبات'][$activeTab] }}</a></section>
@else
<section class="panel class-students-panel"><div class="panel-heading"><div><span class="eyebrow">قائمة الصف</span><h2>طلاب {{ $classroom->name }}</h2></div><span class="muted">  الأرقام الدراسية في ملفات الطلاب</span></div><div class="table-wrap"><table><thead><tr><th>#</th><th>الطالب</th><th>الصف</th><th>المعدل</th><th>حالة الطالب</th><th>حضور اليوم</th><th></th></tr></thead><tbody>
@forelse($classroom->students as $student)
@php($attendanceLabels = ['present' => ['حاضر', 'student-status--present'], 'absent' => ['غائب', 'student-status--absent'], 'late' => ['متأخر', 'student-status--late'], 'excused' => ['بعذر', 'student-status--excused'], 'unrecorded' => ['لم يسجل', 'student-status--unrecorded']])
<tr><td class="student-index">{{ $loop->iteration }}</td><td><span class="student-identity"><i>{{ mb_substr($student->user?->name ?? 'ط', 0, 1) }}</i><strong>{{ $student->user?->name }}</strong></span></td><td>{{ $classroom->name }}</td><td><strong class="student-average">{{ $student->average_grade !== null ? number_format($student->average_grade, 1).'%' : '-' }}</strong></td><td><span class="student-status {{ $student->status === 'active' ? 'student-status--present' : 'student-status--absent' }}">{{ $student->status === 'active' ? 'نشط' : 'موقوف' }}</span></td><td><span class="student-attendance {{ $attendanceLabels[$student->attendance_status][1] }}">{{ $attendanceLabels[$student->attendance_status][0] }}</span></td><td><a class="student-view-link" href="{{ route('teacher.students.show', $student) }}">عرض الملف</a></td></tr>
@empty <tr><td colspan="6">لا يوجد طلاب في هذا الصف.</td></tr>@endforelse
</tbody></table></div></section>
@endif
@endsection
