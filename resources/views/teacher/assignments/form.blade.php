@extends('teacher.layout') @section('title', $assignment ? 'تعديل الواجب' : 'إنشاء واجب') @section('subtitle','حدد المطلوب وموعد التسليم') @section('content')
@php
    $pairsByClassroom = [];
    foreach ($pairs as $p) { $pairsByClassroom[$p->classroom_id][] = $p->subject_id; }
@endphp
<form class="form-card" method="post" action="{{ $assignment ? route('teacher.assignments.update',$assignment->id) : route('teacher.assignments.store') }}" enctype="multipart/form-data" id="assignment-form">
@csrf
@if($assignment) @method('put') @endif
<div class="form-hint">
    اكتب تعليمات واضحة للطلاب وحدد الصف والمادة والدرجة بحيث يكون الواجب مفهومًا وقياسه دقيقًا.
</div>
<div class="form-grid">
<label class="wide">
    <span class="label-head">عنوان الواجب</span>
    <input name="title" required value="{{ old('title', $assignment->title ?? '') }}" placeholder="مثال: حل الأنشطة الصفية - الوحدة الثانية">
</label>
<label>
    <span class="label-head">المادة</span>
    <select name="subject_id" id="a-subject" required>
        <option value="">اختر المادة</option>
        @foreach($subjects as $s)
        <option value="{{ $s->id }}" @selected(old('subject_id', $assignment->subject_id ?? '')==$s->id)>{{ $s->name }}</option>
        @endforeach
    </select>
</label>
<label>
    <span class="label-head">الصف</span>
    <select name="classroom_id" id="a-classroom" required>
        <option value="">اختر الصف</option>
        @foreach($classrooms as $c)
        <option value="{{ $c->id }}" data-subjects="{{ implode(',', $pairsByClassroom[$c->id] ?? []) }}" @selected(old('classroom_id', $assignment->classroom_id ?? '')==$c->id)>{{ $c->name }} {{ $c->section }}</option>
        @endforeach
    </select>
</label>
<label>
    <span class="label-head">الدرجة</span>
    <input type="number" step="0.5" min="0.5" name="max_score" required value="{{ old('max_score', $assignment->max_score ?? 10) }}" placeholder="10">
</label>
<label>
    <span class="label-head">تاريخ التسليم</span>
    <input type="date" name="due_date" required value="{{ old('due_date', $assignment->due_date ?? '') }}">
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
    <div class="rich-editor" contenteditable="true" data-placeholder="اكتب وصف الواجب هنا...">{!! old('description', $assignment->description ?? '') !!}</div>
    <textarea name="description" hidden>{{ old('description', $assignment->description ?? '') }}</textarea>
</label>
<label class="wide">
    <span class="label-head">المرفقات</span>
    <input type="file" name="attachments[]" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png">
    @if(!empty($assignment->attachment_path))<p class="muted">يوجد مرفق حالي — رفع ملف جديد سيستبدله.</p>@endif
</label>
<div class="form-actions">
<button class="btn primary">{{ $assignment ? 'حفظ التعديلات' : 'إنشاء الواجب' }}</button>
<a class="btn secondary" href="{{ route('teacher.assignments.index') }}">إلغاء</a>
</div>
</form>
@section('scripts')
<script>
(function(){
  const classroomSelect = document.getElementById('a-classroom');
  const subjectSelect = document.getElementById('a-subject');
  const editor = document.querySelector('#assignment-form .rich-editor');
  const hiddenField = document.querySelector('#assignment-form textarea[name="description"]');

  function filterClassrooms(){
    const selectedSubject = subjectSelect.value;
    let selectedAllowed = false;
    Array.from(classroomSelect.options).forEach(o => {
      if (!o.value) { o.hidden = false; return; }
      const allowed = (o.dataset.subjects || '').split(',').filter(Boolean);
      o.hidden = selectedSubject !== '' && !allowed.includes(selectedSubject);
      if (!o.hidden && o.selected) selectedAllowed = true;
    });
    if (!selectedAllowed) classroomSelect.value = '';
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
  filterClassrooms();
})();
</script>
@endsection
@endsection
