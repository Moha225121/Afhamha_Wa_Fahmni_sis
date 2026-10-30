@extends('teacher.layout')
@section('title', $lesson->exists ? 'تعديل الدرس' : 'إضافة درس جديد')
@section('subtitle', 'اختر الصف والشعب، ثم أضف الدرس وانشره الآن أو في موعد تختاره')
@section('content')
<link rel="stylesheet" href="{{ asset('css/teacher-lesson-form.css') }}?v=20261024-1">
@php
    $pairsByClassroom = $pairs->groupBy('classroom_id')->map(fn ($items) => $items->pluck('subject_id')->unique()->values());
    $existingClassroomIds = $lesson->exists
        ? ($lesson->classrooms->isNotEmpty() ? $lesson->classrooms->pluck('id')->all() : [$lesson->classroom_id])
        : [];
    $selectedClassroomIds = collect(old('classroom_ids', old('classroom_id') ? [old('classroom_id')] : $existingClassroomIds))
        ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    $existingGradeKeys = $classrooms->whereIn('id', $existingClassroomIds)->pluck('lesson_grade_key')->filter()->unique();
    $hasMixedExistingGrades = $lesson->exists && $existingGradeKeys->count() > 1;
    $defaultGradeKey = $existingGradeKeys->count() === 1
        ? $existingGradeKeys->first()
        : (! $lesson->exists && count($lessonGrades) === 1 ? $lessonGrades[0]['key'] : '');
    $selectedGradeKey = (string) old('grade_key', $defaultGradeKey);
    $selectedSubjectId = old('subject_id', $lesson->subject_id);
    $hasSelectedBranches = $hasMixedExistingGrades || $classrooms->contains(fn ($room) => $room->lesson_grade_key === $selectedGradeKey
        && trim((string) $room->lesson_branch_label) !== ''
        && $pairsByClassroom->get($room->id, collect())->contains((int) $selectedSubjectId));
    $branchScope = old('branch_scope', $lesson->exists ? 'selected' : 'all');
    $pastPublishedLesson = $lesson->exists && $lesson->status === 'published' && $lesson->published_at?->lte(now());
    $defaultPublishMode = $pastPublishedLesson ? 'preserve' : ($lesson->status === 'published' && $lesson->published_at?->isFuture() ? 'scheduled' : 'now');
    $publishMode = old('publish_mode', $defaultPublishMode);
    $publicationValue = old('published_at', $lesson->published_at?->isFuture() ? $lesson->published_at->copy()->timezone($publicationTimezone)->format('Y-m-d\TH:i') : null);
@endphp
<form id="teacher-lesson-form" class="form-card teacher-lesson-form" method="post" enctype="multipart/form-data"
      action="{{ $lesson->exists ? route('teacher.lessons.update', $lesson) : route('teacher.lessons.store') }}"
      data-existing-mixed="{{ $hasMixedExistingGrades ? '1' : '0' }}" data-existing-subject="{{ $lesson->subject_id }}"
      data-is-edit="{{ $lesson->exists ? '1' : '0' }}" data-local-now="{{ $schoolNow->format('Y-m-d\TH:i') }}"
      data-upload-base="{{ route('teacher.lessons.uploads.start') }}" data-upload-user="{{ auth()->id() }}"
      data-upload-max="{{ config('lesson_uploads.max_bytes') }}" data-upload-total="{{ config('lesson_uploads.total_bytes') }}"
      data-upload-video-max="{{ config('lesson_uploads.video_max_bytes') }}"
      data-attachment-upload-url="{{ route('teacher.lessons.attachments.upload') }}"
      data-attachment-discard-url="{{ route('teacher.lessons.attachments.discard', ['token' => '__TOKEN__']) }}">
    @csrf
    @if($lesson->exists) @method('put') @endif
    @unless($lesson->exists)
        <input type="hidden" name="submission_key" value="{{ old('submission_key', (string) \Illuminate\Support\Str::uuid()) }}">
    @endunless

    <section class="lesson-form-section" aria-labelledby="lesson-audience-title">
        <div class="lesson-section-heading"><span class="lesson-step" aria-hidden="true">١</span><div><h2 id="lesson-audience-title">لمن هذا الدرس؟</h2><p>تظهر الصفوف والشعب المسندة إليك فقط.</p></div></div>
        @if($classrooms->isEmpty())
            <p class="lesson-empty-state">لا توجد صفوف أو مواد مسندة إليك. تواصل مع إدارة المدرسة لإضافة الإسناد.</p>
        @endif
        <div class="lesson-fields-grid">
            <label class="lesson-field" for="lesson-grade"><span class="label-head" id="lesson-grade-label">الصف</span>
                <select name="grade_key" id="lesson-grade" @if(! $hasMixedExistingGrades) required @endif>
                    <option value="">{{ $hasMixedExistingGrades ? 'الصفوف الحالية للدرس' : 'اختر الصف' }}</option>
                    @foreach($lessonGrades as $grade)
                        <option value="{{ $grade['key'] }}" data-subjects="{{ implode(',', $grade['subject_ids']) }}" @selected($selectedGradeKey === (string) $grade['key'])>{{ $grade['label'] }}{{ $grade['year'] ? ' — '.$grade['year'] : '' }}</option>
                    @endforeach
                </select>
            </label>
            <label class="lesson-field" for="lesson-subject"><span class="label-head" id="lesson-subject-label">المادة</span>
                <select name="subject_id" id="lesson-subject" required>
                    <option value="">اختر المادة</option>
                    @foreach($subjects as $subject)
                        <option value="{{ $subject->id }}" @selected($selectedSubjectId == $subject->id)>{{ $subject->name }}{{ $subject->code ? ' — '.$subject->code : '' }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        @if($hasMixedExistingGrades)
            <p class="lesson-inline-note" id="lesson-existing-audience-note">هذا الدرس مرتبط بأكثر من صف. اختيار صف جديد يستبدل الصفوف الحالية.</p>
        @endif
        <fieldset class="lesson-branches-fieldset" aria-describedby="lesson-branch-help" @if(! $hasSelectedBranches) hidden @endif>
            <legend>الشعب</legend>
            <input type="hidden" name="branch_scope" value="{{ $branchScope }}">
            <label class="lesson-all-branches"><input type="checkbox" id="lesson-classrooms-all" @checked($branchScope === 'all')><span>جميع الشعب</span></label>
            <div id="lesson-classrooms" class="lesson-branch-grid">
                @foreach($classrooms as $classroom)
                    <label class="lesson-branch-card" data-classroom-option>
                        <input type="checkbox" name="classroom_ids[]" value="{{ $classroom->id }}"
                               data-grade="{{ $classroom->lesson_grade_key }}" data-branch="{{ $classroom->lesson_branch_label }}" data-existing-recipient="{{ in_array((int) $classroom->id, $existingClassroomIds, true) ? '1' : '0' }}"
                               data-subjects="{{ $pairsByClassroom->get($classroom->id, collect())->implode(',') }}"
                               @checked(in_array((int) $classroom->id, $selectedClassroomIds, true))>
                        <span><strong>{{ trim((string) $classroom->lesson_branch_label) !== '' ? 'الشعبة '.$classroom->lesson_branch_label : 'الصف' }}</strong><small class="lesson-branch-grade">{{ $classroom->name }}</small></span>
                    </label>
                @endforeach
            </div>
            <p id="lesson-branch-help" class="lesson-inline-note" aria-live="polite">اختر الصف والمادة لعرض الشعب المتاحة.</p>
        </fieldset>
        <p id="lesson-audience-error" class="lesson-inline-error" role="alert" hidden></p>
    </section>

    <section class="lesson-form-section" aria-labelledby="lesson-content-title">
        <div class="lesson-section-heading"><span class="lesson-step" aria-hidden="true">٢</span><div><h2 id="lesson-content-title">محتوى الدرس</h2><p>أضف عنوانًا واضحًا، ثم المحتوى أو المرفقات.</p></div></div>
        <label class="lesson-field" for="lesson-title"><span class="label-head">عنوان الدرس</span><input id="lesson-title" name="title" required maxlength="255" value="{{ old('title', $lesson->title) }}" placeholder="مثال: جمع الأعداد"></label>
        <div class="lesson-editor-field">
            <span class="label-head" id="lesson-content-label">شرح الدرس <small>اختياري</small></span>
            <div class="rich-toolbar" role="toolbar" aria-label="تنسيق شرح الدرس">
                <button type="button" data-command="bold" title="غامق" aria-label="غامق"><strong>B</strong></button>
                <button type="button" data-command="italic" title="مائل" aria-label="مائل"><em>I</em></button>
                <button type="button" data-command="underline" title="تسطير" aria-label="تسطير"><span style="text-decoration:underline">U</span></button>
                <button type="button" data-command="insertUnorderedList" title="قائمة نقطية" aria-label="قائمة نقطية">•</button>
                <button type="button" data-command="insertOrderedList" title="قائمة مرقمة" aria-label="قائمة مرقمة">1.</button>
                <button type="button" data-command="justifyRight" title="محاذاة لليمين" aria-label="محاذاة لليمين">⇥</button>
                <button type="button" data-command="justifyCenter" title="توسيط" aria-label="توسيط">≡</button>
            </div>
            <div class="rich-editor" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="lesson-content-label" data-placeholder="اكتب شرح الدرس هنا..."></div>
            <textarea name="content" hidden>{{ old('content', $lesson->content) }}</textarea>
        </div>
        <label class="lesson-field lesson-attachments" for="lesson-attachments"><span class="label-head">مرفقات الدرس <small>اختياري</small></span><input id="lesson-attachments" type="file" name="attachments[]" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,.heic,.heif,.mp4"><small class="muted">حتى 10 ملفات، بحد أقصى {{ config('lesson_uploads.video_max_bytes') / 1048576 }} MiB لكل فيديو و{{ config('lesson_uploads.max_bytes') / 1048576 }} MiB لبقية الملفات و1100 MiB لجميع الملفات. يمكنك استئناف الرفع خلال 24 ساعة باختيار الملف الأصلي مجددًا.</small></label>
        <p id="lesson-file-summary" class="lesson-inline-note" aria-live="polite"></p>
        <div id="lesson-uploaded-files" class="lesson-existing-files" aria-live="polite"></div>
        <div id="lesson-attachment-tokens"></div>
        @if($lesson->exists && $lesson->attachments->isNotEmpty())
            <div class="lesson-existing-files"><strong>المرفقات الحالية</strong><ul>@foreach($lesson->attachments as $attachment)<li>{{ $attachment->original_name ?: $attachment->title }}</li>@endforeach</ul><small class="muted">الملفات التي ترفعها تُضاف إلى المرفقات الحالية.</small></div>
        @endif
        <details class="lesson-optional" @if(old('unit_title', $lesson->unit?->title)) open @endif><summary>إضافة اسم الوحدة <span>اختياري</span></summary><label class="lesson-field" for="lesson-unit"><span class="label-head">الوحدة</span><input id="lesson-unit" name="unit_title" maxlength="255" value="{{ old('unit_title', $lesson->unit?->title) }}" placeholder="مثال: الوحدة الأولى"></label></details>
    </section>

    <section class="lesson-form-section" aria-labelledby="lesson-publication-title">
        <div class="lesson-section-heading"><span class="lesson-step" aria-hidden="true">٣</span><div><h2 id="lesson-publication-title">متى يظهر الدرس للطلاب؟</h2></div></div>
        <fieldset class="lesson-publication-fieldset"><legend class="lesson-sr-only">موعد نشر الدرس</legend>
            <div class="lesson-choice-grid">
                @if($pastPublishedLesson)
                    <label class="lesson-choice"><input type="radio" name="publish_mode" value="preserve" @checked($publishMode === 'preserve')><span><strong>الإبقاء على موعد النشر الحالي</strong><small>{{ $lesson->published_at?->copy()->timezone($publicationTimezone)->format('Y-m-d H:i') }}</small></span></label>
                @endif
                <label class="lesson-choice"><input type="radio" name="publish_mode" value="now" @checked($publishMode === 'now')><span><strong>النشر الآن</strong><small>يظهر للطلاب فور حفظه</small></span></label>
                <label class="lesson-choice"><input type="radio" name="publish_mode" value="scheduled" @checked($publishMode === 'scheduled')><span><strong>اختيار موعد</strong><small>حدد اليوم والوقت المناسبين</small></span></label>
            </div>
            <div id="lesson-schedule" class="lesson-schedule" @if($publishMode !== 'scheduled') hidden @endif>
                <label class="lesson-field" for="lesson-published-at"><span class="label-head">التاريخ والوقت</span><input id="lesson-published-at" type="datetime-local" name="published_at" value="{{ $publicationValue }}" @if($publishMode === 'scheduled') required @endif><small class="muted">بتوقيت المدرسة: {{ $publicationTimezone }}</small></label>
            </div>
        </fieldset>
    </section>
    <div class="lesson-submit-bar">
        <p id="lesson-submit-summary" aria-live="polite">حدد الصف والمادة والشعب قبل الحفظ.</p>
        <div class="lesson-submit-actions">
            @if(! $pastPublishedLesson)<button class="btn secondary" type="submit" name="status" value="draft">حفظ كمسودة</button>@endif
            <button class="btn primary" id="lesson-publish-button" type="submit" name="status" value="published" @disabled($classrooms->isEmpty())>{{ $lesson->exists ? 'حفظ التعديلات' : 'نشر الدرس الآن' }}</button>
        </div>
    </div>
    <a class="lesson-back-link" href="{{ route('teacher.lessons.index') }}">العودة إلى الدروس</a>
</form>
@if($pastPublishedLesson)<form class="form-actions teacher-cancel-form" method="post" action="{{ route('teacher.lessons.cancel', $lesson) }}">@csrf @method('patch')<button class="btn secondary" type="submit" data-confirm="هل أنت متأكد من إلغاء هذا الدرس؟">إلغاء الدرس</button></form>@endif
@endsection
@section('scripts')
<script src="{{ asset('js/enhanced-selects.js') }}"></script>
<script src="{{ asset('js/lesson-resumable-upload.js') }}?v=20261026-1"></script>
<script src="{{ asset('js/teacher-lesson-form.js') }}?v=20261026-1"></script>
@endsection
