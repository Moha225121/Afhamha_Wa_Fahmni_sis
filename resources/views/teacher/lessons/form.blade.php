@extends('teacher.layout')
@section('title', $lesson->exists ? 'تعديل الدرس' : 'إنشاء درس جديد')
@section('subtitle', 'أضف محتوى الدرس وانشره للطلاب')
@section('content')
<form class="form-card" method="post" enctype="multipart/form-data" action="{{ $lesson->exists ? route('teacher.lessons.update', $lesson) : route('teacher.lessons.store') }}">
@csrf @if($lesson->exists) @method('put') @endif
@unless($lesson->exists)<input type="hidden" name="submission_key" value="{{ old('submission_key', (string) \Illuminate\Support\Str::uuid()) }}">@endunless
@php($pairsByClassroom = $pairs->groupBy('classroom_id')->map(fn ($items) => $items->pluck('subject_id')->values())->all())
<div class="form-grid">
<label><span class="label-head">المادة</span><select name="subject_id" id="lesson-subject" required><option value="">اختر المادة</option>@foreach($subjects as $subject)<option value="{{ $subject->id }}" @selected(old('subject_id', $lesson->subject_id) == $subject->id)>{{ $subject->name }} - {{ $subject->code }}</option>@endforeach</select></label>
<label><span class="label-head">الصف</span><select name="classroom_id" id="lesson-classroom" required><option value="">اختر الصف</option>@foreach($classrooms as $classroom)<option value="{{ $classroom->id }}" data-subjects="{{ implode(',', ($pairsByClassroom[$classroom->id] ?? collect())->all()) }}" @selected(old('classroom_id', $lesson->classroom_id) == $classroom->id)>{{ $classroom->name }} {{ $classroom->section }}</option>@endforeach</select></label>
<label style="grid-column:1/-1"><span class="label-head">عنوان الدرس</span><input name="title" required value="{{ old('title', $lesson->title) }}"></label>
<label><span class="label-head">الوحدة</span><input name="unit_title" value="{{ old('unit_title', $lesson->unit?->title) }}" placeholder="مثال: الوحدة الأولى"></label>
<label style="grid-column:1/-1"><span class="label-head">محتوى الدرس</span><div class="rich-toolbar"><button type="button" data-command="bold" title="غامق"><strong>B</strong></button><button type="button" data-command="italic" title="مائل"><em>I</em></button><button type="button" data-command="underline" title="تسطير"><span style="text-decoration:underline">U</span></button><button type="button" data-command="insertUnorderedList" title="قائمة نقطية">•</button><button type="button" data-command="insertOrderedList" title="قائمة رقمية">1.</button><button type="button" data-command="justifyRight" title="محاذاة لليمين">⇥</button><button type="button" data-command="justifyCenter" title="توسيط">≡</button></div><div class="rich-editor" contenteditable="true" data-placeholder="اكتب محتوى الدرس هنا..."></div><textarea name="content" hidden>{{ old('content', $lesson->content) }}</textarea></label>
<label><span class="label-head">موعد نشر الدرس</span><input type="datetime-local" name="published_at" value="{{ old('published_at', $lesson->published_at?->format('Y-m-d\TH:i')) }}"><small class="muted">اتركه فارغًا للنشر فورًا</small></label>
<label style="grid-column:1/-1"><span class="label-head">مرفقات الدرس</span><input type="file" name="attachments[]" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,.heic,.heif,.mp4"><small class="muted">حتى 20 ميجابايت للملف و22 ميبيبايت لمجموع الملفات في الطلب.</small>@if($lesson->exists && $lesson->attachments->isNotEmpty())<small class="muted">المرفقات الحالية: {{ $lesson->attachments->count() }}. يمكنك إضافة ملفات أخرى.</small>@endif</label>
</div>
@php($pastPublishedLesson = $lesson->exists && $lesson->status === 'published' && $lesson->published_at?->isPast())
<div class="form-actions"><a class="btn secondary" href="{{ route('teacher.lessons.index') }}">العودة إلى الدروس</a><div class="lesson-actions">@if(! $pastPublishedLesson)<button class="btn secondary" type="submit" name="status" value="draft">حفظ كمسودة</button>@endif<button class="btn primary" type="submit" name="status" value="published">{{ $lesson->exists && $lesson->status === 'published' ? 'تعديل' : 'نشر الدرس' }}</button></div></div>
</form>
@if($pastPublishedLesson)<form class="form-actions teacher-cancel-form" method="post" action="{{ route('teacher.lessons.cancel', $lesson) }}">@csrf @method('patch')<button class="btn secondary" type="submit" data-confirm="هل أنت متأكد من إلغاء هذا الدرس؟">إلغاء</button></form>@endif
@section('scripts')
<script>
(function () {
	const form = document.querySelector('form.form-card');
	const classroom = document.getElementById('lesson-classroom');
	const subject = document.getElementById('lesson-subject');
	const editor = form.querySelector('.rich-editor');
	const content = form.querySelector('textarea[name="content"]');
	let submitting = false;
	function syncSubjects() {
		const selectedSubject = subject.value;
		let selectedAllowed = false;
		Array.from(classroom.options).forEach(option => {
			if (!option.value) { option.hidden = false; return; }
			const allowed = (option.dataset.subjects || '').split(',').filter(Boolean);
			option.hidden = selectedSubject !== '' && !allowed.includes(selectedSubject);
			if (!option.hidden && option.selected) selectedAllowed = true;
		});
		if (!selectedAllowed) classroom.value = '';
	}
	editor.innerHTML = content.value || '';
	editor.addEventListener('input', () => content.value = editor.innerHTML);
	form.addEventListener('submit', () => content.value = editor.innerHTML);
	form.addEventListener('submit', event => {
		const fileInput = form.querySelector('input[name="attachments[]"]');
		const files = Array.from(fileInput?.files || []);
		const maxFileBytes = 20 * 1024 * 1024;
		const maxTotalBytes = 22 * 1024 * 1024;
		const invalidFile = files.find(file => file.size > maxFileBytes);
		if (invalidFile || files.reduce((total, file) => total + file.size, 0) > maxTotalBytes) {
			event.preventDefault();
			window.alert(invalidFile ? 'يجب ألا يتجاوز حجم كل مرفق 20 ميجابايت.' : 'يجب ألا يتجاوز مجموع المرفقات 22 ميبيبايت.');
			return;
		}
		if (submitting) {
			event.preventDefault();
			return;
		}
		submitting = true;
		const submitter = event.submitter;
		if (submitter?.name) {
			const submitterValue = document.createElement('input');
			submitterValue.type = 'hidden';
			submitterValue.name = submitter.name;
			submitterValue.value = submitter.value;
			form.appendChild(submitterValue);
		}
		form.querySelectorAll('button[type="submit"]').forEach(button => {
			button.disabled = true;
			button.dataset.originalText = button.textContent;
			button.textContent = 'جارٍ الحفظ…';
		});
	});
	form.querySelectorAll('.rich-toolbar button').forEach(button => button.addEventListener('click', () => { editor.focus(); document.execCommand(button.dataset.command, false); content.value = editor.innerHTML; }));
	subject.addEventListener('change', syncSubjects);
	syncSubjects();
}());
</script>
@endsection
@endsection
