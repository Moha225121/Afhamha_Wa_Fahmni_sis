@extends('student.layout')
@section('title', $assignment->title)
@section('content')
<section class="page-title"><p>{{ $assignment->subject->name }}</p><h1>{{ $assignment->title }}</h1></section>
@if(session('success')) <div class="notice success" role="status">{{ session('success') }}</div> @endif
@if($errors->any())
<div class="notice error" role="alert">
    <strong>تعذر تسليم الواجب:</strong>
    <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
</div>
@endif
<section class="list-section"><p>{{ $assignment->instructions ?: 'لا توجد تعليمات إضافية.' }}</p><div class="info-list"><div><span>آخر موعد</span><strong>{{ $assignment->due_at?->format('Y-m-d H:i') ?? 'بدون موعد نهائي' }}</strong></div><div><span>الحالة</span><strong>{{ $submission?->submitted_at ? 'تم التسليم في '.$submission->submitted_at->format('Y-m-d H:i') : 'لم يسلّم' }}</strong></div>@foreach($assignment->attachments as $attachment)<div><span>مرفق الواجب {{ $loop->iteration }}</span><strong><a href="{{ route('student.assignments.attachments.file', $attachment) }}">{{ $attachment->original_name }}</a></strong></div>@endforeach @if($submission?->fileAttachment?->hasValidPrivateMetadata())<div><span>ملفك المحفوظ</span><strong><a href="{{ route('student.assignments.submission-file', $submission) }}">{{ $submission->fileAttachment->original_name }}</a></strong></div>@endif</div></section>
@if(!$assignment->due_at || !$assignment->due_at->isPast())
<form class="profile-form assignment-submission-form" method="post" enctype="multipart/form-data" action="{{ route('student.assignments.submit', $assignment) }}" data-student-action-form>
    @csrf
    <div class="form-field">
        <span class="form-label">ملف التسليم</span>
        <div class="file-upload-control">
            <input class="visually-hidden" id="assignment-file" type="file" name="file" required accept="{{ collect(config('student_academic.private_files.allowed_extensions', []))->map(fn ($extension) => '.'.$extension)->implode(',') }}" data-file-input aria-describedby="assignment-file-help assignment-file-name">
            <label class="file-upload-button" for="assignment-file">اختيار ملف</label>
            <span class="file-upload-name" id="assignment-file-name" data-file-name>لم يتم اختيار ملف</span>
        </div>
        <small id="assignment-file-help">الحد الأقصى 10 ميجابايت. استخدم أحد أنواع الملفات المدعومة.</small>
    </div>
    <label>ملاحظات<textarea name="notes" rows="4">{{ old('notes', $submission?->notes) }}</textarea></label>
    <button type="submit" data-loading-text="جارٍ التسليم...">{{ $submission ? 'استبدال التسليم' : 'تسليم الواجب' }}</button>
</form>
@endif
@endsection
