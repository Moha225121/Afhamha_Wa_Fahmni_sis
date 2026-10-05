@extends('admin.layout')
@section('title', $teacher->exists ? 'تعديل المعلم' : 'إضافة معلم')
@section('content')
@php
    $u = $teacher->user;
    $autoEmail = app(\App\Services\SchoolAccountService::class)->settings()['teacher_auto_email'];
    $savedAssignments = $displayAssignments->map(fn ($assignment) => [
        'classroom_id' => $assignment->classroom_id,
        'subject_id' => $assignment->subject_id,
    ])->all();
    $assignmentRows = old('assignments', $savedAssignments);
    $assignmentRowCount = min(30, max(3, count($assignmentRows)));
    $subjectOptions = $subjects->map(fn ($subject) => [
        'id' => (string) $subject->id,
        'label' => $subject->name.' - '.$subject->code,
    ])->values();
@endphp

<form class="form-card" method="post" action="{{ $teacher->exists ? route('admin.teachers.update', $teacher) : route('admin.teachers.store') }}">
    @csrf
    @if($teacher->exists) @method('put') @endif
    <div class="form-grid">
        <label>الاسم<input name="name" value="{{ old('name', $u?->name) }}" required></label>
        @if($teacher->exists || !$autoEmail)
            <label>البريد<input type="text" inputmode="email" name="email" value="{{ old('email', $u?->email) }}" required></label>
        @else
            <p class="muted">سيُنشأ البريد المدرسي تلقائيًا بعد الحفظ.</p>
        @endif
        <label>الاسم الأول بالإنجليزية<input dir="ltr" name="first_name_en" value="{{ old('first_name_en', $u?->first_name_en) }}" {{ !$teacher->exists && $autoEmail ? 'required' : '' }}></label>
        <label>اسم العائلة بالإنجليزية<input dir="ltr" name="last_name_en" value="{{ old('last_name_en', $u?->last_name_en) }}"></label>
        <label>الهاتف<input name="phone" value="{{ old('phone', $u?->phone) }}"></label>
        <label>التخصص<input name="specialization" value="{{ old('specialization', $teacher->specialization) }}"></label>
        <label>الحالة<select name="status"><option value="active" @selected(old('status', $teacher->status) === 'active')>نشط</option><option value="inactive" @selected(old('status', $teacher->status) === 'inactive')>موقوف</option></select></label>
        <label>كلمة المرور <small>{{ $teacher->exists ? 'اتركها فارغة للإبقاء على كلمة المرور الحالية.' : 'اختياري: اتركها فارغة لاستخدام كلمة المرور الافتراضية المحددة من الإدارة.' }}</small><input type="password" name="password" autocomplete="new-password"></label>
        <label>تأكيد كلمة المرور<input type="password" name="password_confirmation" autocomplete="new-password"></label>
    </div>

    <div class="teacher-assignment-heading">
        <div>
            <h2>الإسنادات</h2>
            <p class="muted">اختر الصف والمادة. خيار «جميع الشعب» يسند المادة إلى شعب الصف كلها في إسناد واحد. يمكنك إضافة حتى 30 إسنادًا.</p>
        </div>
        <button class="btn secondary" type="button" id="add-teacher-assignment">＋ إضافة إسناد</button>
    </div>

    <div id="teacher-assignment-list" data-max-assignments="30">
        @for($i = 0; $i < $assignmentRowCount; $i++)
            @php
                $assignment = $assignmentRows[$i] ?? [];
                $selectedClassroomId = (string) ($assignment['classroom_id'] ?? '');
                $selectedSubjectId = (string) ($assignment['subject_id'] ?? '');
                $allowedSubjectIds = $classroomSubjects[$selectedClassroomId] ?? [];
            @endphp
            <div class="form-grid teacher-assignment-row">
                <label>الصف
                    <select class="teacher-assignment-classroom" name="assignments[{{ $i }}][classroom_id]">
                        <option value="">اختر الصف</option>
                        @foreach($classroomOptions as $option)
                            <option value="{{ $option['id'] }}" @selected($selectedClassroomId === (string) $option['id'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    <small class="teacher-assignment-group-hint" aria-live="polite"></small>
                </label>
                <label>المادة
                    <select class="teacher-assignment-subject" name="assignments[{{ $i }}][subject_id]" data-selected-subject="{{ $selectedSubjectId }}" @disabled(!$selectedClassroomId)>
                        <option value="">{{ $selectedClassroomId ? 'اختر المادة' : 'اختر الصف أولًا' }}</option>
                        @foreach($subjects as $subject)
                            @if(in_array((string) $subject->id, array_map('strval', $allowedSubjectIds), true))
                                <option value="{{ $subject->id }}" @selected($selectedSubjectId === (string) $subject->id)>{{ $subject->name }} - {{ $subject->code }}</option>
                            @endif
                        @endforeach
                    </select>
                </label>
                <button class="btn secondary remove-teacher-assignment" type="button" aria-label="حذف الإسناد {{ $i + 1 }}" @if($i < 3) hidden @endif>حذف الإسناد</button>
            </div>
        @endfor
    </div>

    <template id="teacher-assignment-template">
        <div class="form-grid teacher-assignment-row">
            <label>الصف
                <select class="teacher-assignment-classroom" data-name="assignments[__INDEX__][classroom_id]">
                    <option value="">اختر الصف</option>
                    @foreach($classroomOptions as $option)
                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
                <small class="teacher-assignment-group-hint" aria-live="polite"></small>
            </label>
            <label>المادة
                <select class="teacher-assignment-subject" data-name="assignments[__INDEX__][subject_id]" disabled>
                    <option value="">اختر الصف أولًا</option>
                </select>
            </label>
            <button class="btn secondary remove-teacher-assignment" type="button">حذف الإسناد</button>
        </div>
    </template>

    <div class="form-actions"><button class="btn primary">حفظ</button><a class="btn secondary" href="{{ route('admin.teachers.index') }}">إلغاء</a></div>
</form>
@endsection

@push('scripts')
<script>
    window.teacherAssignmentData = {
        classroomSubjects: {{ \Illuminate\Support\Js::from($classroomSubjects) }},
        classroomGroups: {{ \Illuminate\Support\Js::from($classroomGroups) }},
        subjects: {{ \Illuminate\Support\Js::from($subjectOptions) }},
    };
</script>
<script src="{{ asset('js/admin-teacher-form.js') }}?v=20261005-2" defer></script>
@endpush
