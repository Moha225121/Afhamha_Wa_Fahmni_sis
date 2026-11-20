@extends('admin.layout') @section('title',$teacher->exists?'تعديل المعلم':'إضافة معلم') @section('content')
@php
    $u = $teacher->user;
    $autoEmail = app(\App\Services\SchoolAccountService::class)->settings()['teacher_auto_email'];
@endphp
<form class="form-card" method="post" action="{{ $teacher->exists?route('admin.teachers.update',$teacher):route('admin.teachers.store') }}">@csrf @if($teacher->exists)@method('put')@endif
<div class="form-grid"><label>الاسم<input name="name" value="{{ old('name',$u?->name) }}" required></label>@if($teacher->exists || !$autoEmail)<label>البريد<input type="text" inputmode="email" name="email" value="{{ old('email',$u?->email) }}" required></label>@else<p class="muted">سيُنشأ البريد المدرسي تلقائيًا بعد الحفظ.</p>@endif<label>الاسم الأول بالإنجليزية<input dir="ltr" name="first_name_en" value="{{ old('first_name_en',$u?->first_name_en) }}" {{ !$teacher->exists && $autoEmail ? 'required' : '' }}></label><label>اسم العائلة بالإنجليزية<input dir="ltr" name="last_name_en" value="{{ old('last_name_en',$u?->last_name_en) }}"></label><label>الهاتف<input name="phone" value="{{ old('phone',$u?->phone) }}"></label><label>التخصص<input name="specialization" value="{{ old('specialization',$teacher->specialization) }}"></label><label>الحالة<select name="status"><option value="active" @selected($teacher->status==='active')>نشط</option><option value="inactive" @selected($teacher->status==='inactive')>موقوف</option></select></label><label>كلمة المرور <small>{{ $teacher->exists ? 'اتركها فارغة للإبقاء على كلمة المرور الحالية.' : 'اختياري: اتركها فارغة لاستخدام كلمة المرور الافتراضية المحددة من الإدارة.' }}</small><input type="password" name="password" autocomplete="new-password"></label><label>تأكيد كلمة المرور<input type="password" name="password_confirmation" autocomplete="new-password"></label></div>
@php
    $assignmentLimit = \App\Http\Requests\TeacherRequest::MAX_ASSIGNMENTS;
    $formAssignments = collect(old('assignments', $displayAssignments))->values();
    $assignmentRows = max($assignmentLimit, $formAssignments->count());
    $assignmentData = [
        'classroomSubjects' => $classroomSubjects,
        'classroomGroups' => $classroomGroups,
        'subjects' => $subjects->map(fn ($subject) => ['id' => $subject->id, 'label' => $subject->name.' - '.$subject->code])->values(),
    ];
@endphp
<div class="teacher-assignment-heading">
    <div>
        <h2>إسناد الصفوف والمواد</h2>
        <p class="muted">اختر الشعبة ومادتها في سطر مستقل لكل إسناد، حتى 6 إسنادات. اختر «جميع الشعب» إذا كان المعلم يدرّس المادة في كل شعب الصف.</p>
        <a href="{{ route('admin.classes.sections') }}">إدارة شعب الصفوف من الأول إلى السادس</a>
    </div>
    <button class="btn secondary" type="button" id="add-teacher-assignment" disabled>إضافة إسناد</button>
</div>
<p class="muted">عند التعديل تُحفظ القائمة المعروضة بدل الإسنادات السابقة. تظهر الإسنادات القديمة الزائدة للحفاظ عليها.</p>
<div id="teacher-assignment-list" data-max-assignments="{{ $assignmentLimit }}">
    @for($i = 0; $i < $assignmentRows; $i++)
        @include('admin.teachers.assignment-row', ['index' => $i, 'assignment' => $formAssignments->get($i)])
    @endfor
</div>
<template id="teacher-assignment-template">
    @include('admin.teachers.assignment-row', ['index' => '__INDEX__', 'assignment' => null])
</template>
<div class="form-actions"><button class="btn primary">حفظ</button><a class="btn secondary" href="{{ route('admin.teachers.index') }}">إلغاء</a></div></form>@endsection
@push('scripts')
<script>
window.teacherAssignmentData = @json($assignmentData);
</script>
<script src="{{ asset('js/admin-teacher-form.js') }}?v=20261120" defer></script>
@endpush
