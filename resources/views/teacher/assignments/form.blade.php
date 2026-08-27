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
    <textarea name="description" rows="5" placeholder="اكتب تعليمات الواجب، المطلوب، الوقت المحدد، والمصادر أو النقاط المهمة للطلاب...">{{ old('description', $assignment->description ?? '') }}</textarea>
</label>
<label class="wide">
    <span class="label-head">المرفقات</span>
    <input type="file" name="attachment">
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
  function filterSubjects(){
    const opt = classroomSelect.options[classroomSelect.selectedIndex];
    const allowed = (opt && opt.dataset.subjects) ? opt.dataset.subjects.split(',').filter(Boolean) : [];
    let hasSelected = false;
    Array.from(subjectSelect.options).forEach(o => {
      if (!o.value) { o.hidden = false; return; }
      const ok = allowed.includes(o.value);
      o.hidden = !ok;
      if (ok && o.selected) hasSelected = true;
    });
    if (!hasSelected) subjectSelect.value = '';
  }
  classroomSelect.addEventListener('change', filterSubjects);
  filterSubjects();
})();
</script>
@endsection
@endsection
