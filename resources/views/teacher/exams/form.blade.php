@extends('teacher.layout') @section('title', $exam ? 'متابعة الاختبار' : 'إنشاء اختبار') @section('subtitle','أضف الأسئلة وحدد الإجابات والدرجات') @section('content')
@php
    $pairsByClassroom = [];
    foreach ($pairs as $p) { $pairsByClassroom[$p->classroom_id][] = $p->subject_id; }
    $existingQuestions = $questions->map(function ($q) {
        return [
            'type' => $q->type,
            'text' => $q->text,
            'score' => (float) $q->score,
            'choices' => collect($q->choices ?? [])->map(fn ($c) => ['text' => $c->text, 'is_correct' => (bool) $c->is_correct])->values(),
        ];
    })->values();
@endphp
<form class="form-card" method="post" action="{{ $exam ? route('teacher.exams.update',$exam->id) : route('teacher.exams.store') }}" id="exam-form" style="max-width:100%; width:min(1400px, 100%); margin:0 auto;">
@csrf
@if($exam) @method('put') @endif
<div class="form-hint">
    صمم الاختبار بشكل واضح: اختر الصف والمادة، ثم أضف الأسئلة مع تحديد درجة كل سؤال وتأكد من صحة الإجابات قبل النشر.
</div>
<div class="form-grid">
<label>
    <span class="label-head">عنوان الاختبار</span>
    <input name="title" required value="{{ old('title', $exam->title ?? '') }}" placeholder="مثال: اختبار الوحدة الأولى في التفسير">
</label>
<label>
    <span class="label-head">الصف</span>
    <select name="classroom_id" id="classroom-select" required>
        <option value="">اختر الصف</option>
        @foreach($classrooms as $c)
        <option value="{{ $c->id }}" data-subjects="{{ implode(',', $pairsByClassroom[$c->id] ?? []) }}" @selected(old('classroom_id', $exam->classroom_id ?? '')==$c->id)>{{ $c->name }} {{ $c->section }}</option>
        @endforeach
    </select>
</label>
<label>
    <span class="label-head">المادة</span>
    <select name="subject_id" id="subject-select" required>
        <option value="">اختر المادة</option>
        @foreach($subjects as $s)
        <option value="{{ $s->id }}" @selected(old('subject_id', $exam->subject_id ?? '')==$s->id)>{{ $s->name }}</option>
        @endforeach
    </select>
</label>
<label>
    <span class="label-head">تاريخ الاختبار</span>
    <input type="datetime-local" name="starts_at" required value="{{ old('starts_at', isset($exam->starts_at) ? \Illuminate\Support\Carbon::parse($exam->starts_at)->format('Y-m-d\TH:i') : '') }}">
</label>
<label>
    <span class="label-head">المدة بالدقائق</span>
    <input type="number" min="1" max="600" name="duration_minutes" required value="{{ old('duration_minutes', $exam->duration_minutes ?? 30) }}">
</label>
</div>

<div id="questions-container" style="margin-top:22px"></div>

<div class="form-actions" style="justify-content:space-between">
<button type="button" class="btn secondary" id="add-question">إضافة سؤال جديد</button>
<div style="display:flex;gap:10px">
@if(!$exam || $exam->status === 'draft')
<button type="submit" name="status" value="draft" class="btn secondary">حفظ كمسودة</button>
@endif
<button type="submit" name="status" value="scheduled" class="btn primary">{{ $exam && $exam->status === 'scheduled' ? 'تحديث الاختبار' : 'نشر الاختبار' }}</button>
</div>
</div>
</form>

<script id="existing-questions-data" type="application/json">{!! json_encode($existingQuestions) !!}</script>

@section('scripts')
<style>
  #questions-container {
    display: flex;
    flex-direction: column;
    gap: 18px;
    width: 100%;
  }

  .question-card {
    width: 100%;
    max-width: none;
    border: 1px solid rgba(148, 163, 184, 0.35);
    background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(248,250,252,0.98));
    border-radius: 18px;
    padding: 20px;
    box-shadow: 0 10px 25px rgba(15, 23, 42, 0.05);
  }

  .question-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 18px;
    padding-bottom: 12px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.25);
  }

  .question-head h2 {
    margin: 0;
    font-size: 1.05rem;
    color: #0f172a;
  }

  .choices-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 14px;
  }

  .choice-row {
    display: grid;
    grid-template-columns: 32px 1fr;
    gap: 10px;
    align-items: center;
    background: rgba(255,255,255,0.7);
    border: 1px solid rgba(148, 163, 184, 0.22);
    border-radius: 12px;
    padding: 10px 12px;
  }

  .choice-row input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: #1d4ed8;
  }

  .choice-row input[type="text"],
  .question-card textarea,
  .question-card input[type="number"],
  .question-card select {
    border: 1px solid rgba(148, 163, 184, 0.5);
    border-radius: 10px;
    padding: 10px 12px;
    background: rgba(255,255,255,0.8);
    color: #0f172a;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
  }

  .question-card textarea {
    min-height: 110px;
    resize: vertical;
  }

  .question-card textarea:focus,
  .question-card input:focus,
  .question-card select:focus {
    border-color: rgba(59, 130, 246, 0.7);
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.12);
    outline: none;
  }

  .question-card .form-grid {
    margin-bottom: 14px;
  }

  .question-card .add-choice {
    margin-top: 12px;
    width: fit-content;
  }

  .rich-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
    padding: 8px 10px;
    border: 1px solid rgba(148, 163, 184, 0.4);
    border-radius: 12px;
    background: rgba(248, 250, 252, 0.9);
  }

  .rich-toolbar button {
    border: 1px solid rgba(148, 163, 184, 0.45);
    background: #fff;
    color: #1f2937;
    border-radius: 8px;
    padding: 6px 9px;
    min-width: 34px;
    cursor: pointer;
    font-weight: 700;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }

  .rich-toolbar button:hover {
    background: #eef4ff;
    border-color: rgba(59, 130, 246, 0.5);
  }

  .rich-editor {
    width: 100%;
    min-height: 110px;
    padding: 12px 14px;
    border: 1px solid rgba(148, 163, 184, 0.45);
    border-radius: 12px;
    background: #ffffff;
    color: #0f172a;
    line-height: 1.8;
    text-align: right;
    direction: rtl;
    overflow: auto;
  }

  .rich-editor:empty::before {
    content: attr(data-placeholder);
    color: #94a3b8;
  }

  .rich-editor ul,
  .rich-editor ol {
    padding-right: 22px;
    margin: 10px 0;
  }

  .rich-editor p {
    margin: 0 0 8px;
  }

  .remove-question {
    border: none;
    background: transparent;
    color: #dc2626;
    font-weight: 600;
    padding: 0;
  }
</style>
<script>
(function(){
  const container = document.getElementById('questions-container');
  const classroomSelect = document.getElementById('classroom-select');
  const subjectSelect = document.getElementById('subject-select');
  let qCount = 0;

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

  function buildRichToolbar(prefix, isInline){
    const items = [
      { label: 'B', cmd: 'bold', title: 'غامق' },
      { label: 'I', cmd: 'italic', title: 'مائل' },
      { label: 'U', cmd: 'underline', title: 'تسطير' },
      { label: '•', cmd: 'insertUnorderedList', title: 'قائمة نقطية' },
      { label: '≡', cmd: 'justifyLeft', title: 'محاذاة يسار' },
      { label: '≣', cmd: 'justifyCenter', title: 'محاذاة وسط' },
      { label: '⇢', cmd: 'justifyRight', title: 'محاذاة يمين' },
      { label: '❝', cmd: 'formatBlock', value: 'blockquote', title: 'اقتباس' }
    ];

    return '<div class="rich-toolbar">' + items.map(item => {
      return '<button type="button" data-command="' + item.cmd + '" data-value="' + (item.value || '') + '" title="' + item.title + '">' + item.label + '</button>';
    }).join('') + '</div>';
  }

  function bindRichEditor(editor, hiddenInput){
    hiddenInput.value = editor.innerHTML;
    editor.addEventListener('input', function () {
      hiddenInput.value = editor.innerHTML;
    });
    editor.addEventListener('blur', function () {
      hiddenInput.value = editor.innerHTML;
    });

    editor.parentElement.querySelectorAll('.rich-toolbar button').forEach(button => {
      button.addEventListener('click', function () {
        const command = button.dataset.command;
        const value = button.dataset.value || null;
        editor.focus();
        document.execCommand(command, false, value);
        hiddenInput.value = editor.innerHTML;
      });
    });
  }

  function choiceRow(qIdx, cIdx, text, checked){
    text = text || '';
    const safeText = text.replace(/"/g, '&quot;');
    return `<div class="choice-row">
      <input type="checkbox" class="correct-toggle" data-group="q${qIdx}" name="questions[${qIdx}][choices][${cIdx}][is_correct]" value="1" ${checked?'checked':''} title="إجابة صحيحة">
      <input type="text" name="questions[${qIdx}][choices][${cIdx}][text]" value="${safeText}" placeholder="نص الخيار">
    </div>`;
  }

  function questionBlock(qIdx, data){
    data = data || {type:'mcq', text:'', score:1, choices:[{text:'',is_correct:true},{text:'',is_correct:false}]};
    const wrap = document.createElement('div');
    wrap.className = 'panel question-card';
    wrap.dataset.qidx = qIdx;
    wrap.innerHTML = `
      <div class="question-head">
        <h2>السؤال ${qIdx+1}</h2>
        <button type="button" class="link-danger remove-question">حذف</button>
      </div>
      <div class="form-grid">
        <label>نوع السؤال<select name="questions[${qIdx}][type]" class="type-select">
          <option value="mcq" ${data.type==='mcq'?'selected':''}>اختيار من متعدد</option>
          <option value="true_false" ${data.type==='true_false'?'selected':''}>صح / خطأ</option>
          <option value="short_answer" ${data.type==='short_answer'?'selected':''}>إجابة قصيرة</option>
        </select></label>
        <label>الدرجة<input type="number" step="0.25" min="0.25" name="questions[${qIdx}][score]" value="${data.score}"></label>
      </div>
      <div>
        ${buildRichToolbar('question-' + qIdx, false)}
        <div class="rich-editor" contenteditable="true" data-placeholder="اكتب نص السؤال هنا...">${data.text || ''}</div>
        <textarea hidden name="questions[${qIdx}][text]">${(data.text || '').replace(/"/g, '&quot;')}</textarea>
      </div>
      <div class="choices-list"></div>
      <button type="button" class="btn secondary small add-choice">إضافة خيار</button>
    `;

    const choicesList = wrap.querySelector('.choices-list');
    const editor = wrap.querySelector('.rich-editor');
    const hiddenInput = wrap.querySelector('textarea[name="questions[' + qIdx + '][text]"]');
    bindRichEditor(editor, hiddenInput);

    const typeSelect = wrap.querySelector('.type-select');
    if (typeSelect && typeSelect.value !== 'mcq' && typeSelect.value !== 'true_false') {
      choicesList.style.display = 'none';
      addChoiceBtn.style.display = 'none';
    }
    const addChoiceBtn = wrap.querySelector('.add-choice');
    let cCount = 0;

    function addChoice(text, checked){
      choicesList.insertAdjacentHTML('beforeend', choiceRow(qIdx, cCount, text, checked));
      cCount++;
    }

    function applyType(){
      const type = wrap.querySelector('.type-select').value;
      if (type === 'short_answer') {
        choicesList.style.display = 'none';
        addChoiceBtn.style.display = 'none';
      } else if (type === 'true_false') {
        choicesList.style.display = '';
        addChoiceBtn.style.display = 'none';
        choicesList.innerHTML = '';
        cCount = 0;
        addChoice('صحيح', true);
        addChoice('خطأ', false);
        choicesList.querySelectorAll('input[type=text]').forEach(i => i.readOnly = true);
      } else {
        choicesList.style.display = '';
        addChoiceBtn.style.display = '';
      }
    }

    wrap.querySelector('.type-select').addEventListener('change', applyType);
    wrap.querySelector('.remove-question').addEventListener('click', () => { wrap.remove(); renumber(); });
    addChoiceBtn.addEventListener('click', () => addChoice('', false));
    choicesList.addEventListener('change', (e) => {
      if (!e.target.classList.contains('correct-toggle')) return;
      if (e.target.checked) {
        choicesList.querySelectorAll('.correct-toggle').forEach(cb => { if (cb !== e.target) cb.checked = false; });
      }
    });

    if (data.type === 'true_false') {
      applyType();
    } else if (data.choices && data.choices.length) {
      data.choices.forEach(c => addChoice(c.text, c.is_correct));
      applyType();
    } else {
      addChoice('', true);
      addChoice('', false);
      applyType();
    }

    return wrap;
  }

  function renumber(){
    container.querySelectorAll('.question-card').forEach((el, i) => {
      el.querySelector('h2').textContent = 'السؤال ' + (i+1);
    });
  }

  function addQuestion(data){
    container.appendChild(questionBlock(qCount, data));
    qCount++;
  }

  document.getElementById('add-question').addEventListener('click', () => addQuestion());

  const existing = JSON.parse(document.getElementById('existing-questions-data').textContent || '[]');
  if (existing.length) {
    existing.forEach(q => addQuestion(q));
  } else {
    addQuestion();
  }
})();
</script>
@endsection
@endsection
