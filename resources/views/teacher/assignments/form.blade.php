@extends('teacher.layout') @section('title', $assignment ? 'تعديل الواجب' : 'إنشاء واجب') @section('subtitle','حدد المطلوب وموعد التسليم') @section('content')
@php
    $pairsByClassroom = [];
    foreach ($pairs as $p) { $pairsByClassroom[$p->classroom_id][] = $p->subject_id; }
    $existingDescription = trim((string) ($assignment->description ?? '')) !== '' ? $assignment->description : ($assignment->instructions ?? '');
    $safeDescription = app(\App\Support\RichText::class)->sanitize(old('description', $existingDescription));
    $existingDueDay = $assignment ? \App\Support\AssignmentDeadline::for(new \App\Models\Assignment(['due_date' => $assignment->due_date, 'due_at' => $assignment->due_at]))?->setTimezone(\App\Services\LessonPublicationTime::timezone())->toDateString() : '';
@endphp
<form class="form-card" method="post" action="{{ $assignment ? route('teacher.assignments.update',$assignment->id) : route('teacher.assignments.store') }}" enctype="multipart/form-data" id="assignment-form">
@csrf
@if(!$assignment)<input type="hidden" name="creation_token" value="{{ $creationToken }}">@endif
@if($assignment) @method('put') @endif
<div class="form-hint">
    اكتب تعليمات واضحة للطلاب وحدد الصف والمادة والدرجة بحيث يكون الواجب مفهومًا وقياسه دقيقًا.
</div>
<div class="form-grid">
<label class="wide">
    <span class="label-head">عنوان الواجب</span>
    <input name="title" required value="{{ old('title', $assignment->title ?? '') }}" placeholder="مثال: حل الأنشطة الصفية - الوحدة الثانية">
</label>
<div>
    <span class="label-head">المادة</span>
    <select name="subject_id" id="a-subject" required>
        <option value="">اختر المادة</option>
        @foreach($subjects as $s)
        <option value="{{ $s->id }}" @selected(old('subject_id', $assignment->subject_id ?? '')==$s->id)>{{ $s->name }}</option>
        @endforeach
    </select>
</div>
<div>
    <span class="label-head">الفصول</span>
    <label><input type="checkbox" id="a-all-classrooms"> جميع الفصول المسندة للمادة</label>
    <div id="a-classrooms" style="display:grid;gap:.5rem;margin-top:.5rem">
        @foreach($classrooms as $c)
        <label data-classroom-option><input type="checkbox" name="classroom_ids[]" value="{{ $c->id }}" data-subjects="{{ implode(',', $pairsByClassroom[$c->id] ?? []) }}" @checked(in_array((int)$c->id, array_map('intval', old('classroom_ids', $selectedClassroomIds)), true))> {{ $c->name }} {{ $c->section }}</label>
        @endforeach
    </div>
</div>
<label>
    <span class="label-head">الدرجة</span>
    <input type="number" step="0.5" min="0.5" name="max_score" required value="{{ old('max_score', $assignment->max_score ?? 10) }}" placeholder="10">
</label>
<label>
    <span class="label-head">تاريخ التسليم</span>
    <input type="date" name="due_date" required value="{{ old('due_date', $existingDueDay) }}">
</label>
</div>
<label class="wide">
    <span class="label-head">الوصف</span>
    <div class="rich-toolbar">
        <button type="button" data-command="bold" title="غامق"><strong>B</strong></button>
        <button type="button" data-command="italic" title="مائل"><em>I</em></button>
        <button type="button" data-command="underline" title="تسطير"><span style="text-decoration:underline;">U</span></button>
        <button type="button" data-command="insertUnorderedList" title="قائمة نقطية">•</button>
        <button type="button" data-command="justifyLeft" title="يسار">⇤</button>
        <button type="button" data-command="justifyCenter" title="وسط">≡</button>
        <button type="button" data-command="justifyRight" title="يمين">⇥</button>
    </div>
    <div class="rich-editor" contenteditable="true" role="textbox" aria-multiline="true" aria-label="وصف الواجب" data-placeholder="اكتب وصف الواجب هنا...">{!! $safeDescription !!}</div>
    <textarea name="description" hidden>{{ $safeDescription }}</textarea>
</label>
<label class="wide">
    <span class="label-head">المرفقات</span>
    <input type="file" name="attachments[]" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png,.webp,.heic,.heif">
    <small class="muted">حتى 20 ميجابايت للملف و22 ميبيبايت لمجموع الملفات في الطلب.</small>
    @if(!empty($assignment->attachment_path))<p class="muted">يوجد مرفق حالي — رفع ملف جديد سيستبدله.</p>@endif
</label>
<div class="form-actions">
<button class="btn primary">{{ $assignment ? 'حفظ التعديلات' : 'إنشاء الواجب' }}</button>
<a class="btn secondary" href="{{ route('teacher.assignments.index') }}">العودة إلى الواجبات</a>
</div>
</form>
@section('scripts')
<script>
(function(){
  const classroomOptions = Array.from(document.querySelectorAll('[data-classroom-option]'));
  const classroomChecks = classroomOptions.map(label => label.querySelector('input'));
  const allClassrooms = document.getElementById('a-all-classrooms');
  const subjectSelect = document.getElementById('a-subject');
  const editor = document.querySelector('#assignment-form .rich-editor');
  const hiddenField = document.querySelector('#assignment-form textarea[name="description"]');
  @if(!$assignment)
  let submitting = false;
  document.getElementById('assignment-form').addEventListener('submit', event => {
    if (submitting) { event.preventDefault(); return; }
    submitting = true;
  });
  window.addEventListener('pageshow', () => { submitting = false; });
  @endif

  function filterClassrooms(){
    const selectedSubject = subjectSelect.value;
    classroomOptions.forEach((label, index) => {
      const check = classroomChecks[index];
      const allowed = (check.dataset.subjects || '').split(',').filter(Boolean);
      label.hidden = selectedSubject !== '' && !allowed.includes(selectedSubject);
      if (label.hidden) check.checked = false;
    });
    allClassrooms.checked = classroomChecks.filter((check, index) => !classroomOptions[index].hidden).length > 0
      && classroomChecks.every((check, index) => classroomOptions[index].hidden || check.checked);
  }

  if (editor && hiddenField) {
    editor.innerHTML = hiddenField.value || '';
    editor.addEventListener('input', () => {
      hiddenField.value = editor.innerHTML;
    });
    editor.addEventListener('blur', () => {
      hiddenField.value = editor.innerHTML;
    });
    document.querySelectorAll('#assignment-form .rich-toolbar button').forEach(button => {
      button.addEventListener('click', () => {
        const command = button.dataset.command;
        editor.focus();
        document.execCommand(command, false, null);
        hiddenField.value = editor.innerHTML;
      });
    });
  }

  subjectSelect.addEventListener('change', filterClassrooms);
  allClassrooms.addEventListener('change', () => classroomChecks.forEach((check, index) => { if (!classroomOptions[index].hidden) check.checked = allClassrooms.checked; }));
  classroomChecks.forEach(check => check.addEventListener('change', filterClassrooms));
  filterClassrooms();
})();
</script>
@endsection
@endsection
