@extends('student.layout')
@section('title', $assignment->title)
@section('content')
<link rel="stylesheet" href="{{ asset('css/homework.css') }}?v=20261025-1">
@php
    $deadline = \App\Support\AssignmentDeadline::for($assignment);
    $schoolTimezone = \App\Services\LessonPublicationTime::timezone();
    $closed = $deadline && now()->gte($deadline);
    $graded = $submission && ($submission->graded_at || $submission->score !== null || $submission->status === 'graded');
    $allowedExtensions = config('student_academic.private_files.allowed_extensions', []);
@endphp
<section class="page-title"><p>{{ $assignment->subject?->name ?? 'مادة غير محددة' }}</p><h1>{{ $assignment->title }}</h1></section>
@if(session('success'))
    <div class="notice success" role="status">{{ session('success') }}</div>
@endif
@if($errors->any())
    <div class="notice error" role="alert">{{ $errors->first() }}</div>
@endif
<section class="list-section homework-details" aria-label="تفاصيل الواجب">
    <h2>تعليمات الواجب</h2>
    @include('shared.homework-instructions', ['assignment' => $assignment])
    <div class="info-list">
        <div><span>آخر موعد للتسليم</span><strong>@if($deadline)<time datetime="{{ $deadline->toIso8601String() }}">{{ $deadline->setTimezone($schoolTimezone)->format('Y-m-d H:i') }}</time>@else بدون موعد نهائي @endif</strong></div>
        <div><span>الحالة</span><strong>{{ $graded ? 'تم التصحيح' : ($submission?->submitted_at ? 'تم التسليم في '.$submission->submitted_at->copy()->setTimezone($schoolTimezone)->format('Y-m-d H:i') : 'لم يسلّم') }}</strong></div>
        @foreach($assignment->attachments as $attachment)
            <div><span>مرفق الواجب {{ $loop->iteration }}</span><strong><a href="{{ route('student.assignments.attachments.file', $attachment) }}">{{ $attachment->original_name }}</a></strong></div>
        @endforeach
        @if($submission?->fileAttachment?->hasValidPrivateMetadata())
            <div><span>ملفك المحفوظ</span><strong><a href="{{ route('student.assignments.submission-file', $submission) }}">{{ $submission->fileAttachment->original_name }}</a></strong></div>
        @endif
        @if($submission?->notes)
            <div><span>ملاحظاتك</span><strong>{{ $submission->notes }}</strong></div>
        @endif
    </div>
</section>
@if($graded)
    <section class="homework-notice is-graded" aria-label="تم تصحيح الواجب"><strong>تم تصحيح تسليمك</strong><p>لا يمكن استبدال الملف بعد التصحيح. يمكنك الرجوع إلى ملفك المحفوظ أعلاه.</p></section>
@elseif($closed)
    <section class="homework-notice is-closed" aria-label="انتهى موعد التسليم"><strong>انتهى موعد تسليم هذا الواجب.</strong><p>{{ $submission?->submitted_at ? 'تسليمك محفوظ، ويمكنك فتحه من الرابط أعلاه.' : 'لم يُسجّل تسليم لهذا الواجب. تواصل مع المعلم إذا احتجت إلى تمديد الموعد.' }}</p></section>
@else
    <form class="profile-form homework-upload" method="post" enctype="multipart/form-data" action="{{ route('student.assignments.submit', $assignment) }}" aria-label="تسليم الواجب">
        @csrf
        <h2>{{ $submission ? 'استبدال ملف التسليم' : 'تسليم الواجب' }}</h2>
        <label for="homework-file">اختر ملف الحل<input id="homework-file" type="file" name="file" accept="{{ collect($allowedExtensions)->map(fn ($extension) => '.'.$extension)->join(',') }}" aria-describedby="homework-upload-help" required></label>
        <p id="homework-upload-help" class="homework-upload-help">يمكنك رفع صورة JPG أو PNG، أو ملف PDF أو مستند. الحد الأقصى {{ number_format(config('student_academic.private_files.max_kilobytes', 10240) / 1024) }} ميجابايت.</p>
        <label for="homework-notes">ملاحظات للمعلم (اختياري)<textarea id="homework-notes" name="notes" rows="3" maxlength="2000">{{ old('notes', $submission?->notes) }}</textarea></label>
        <button type="submit">{{ $submission ? 'حفظ الملف البديل' : 'تسليم الواجب' }}</button>
    </form>
@endif
@endsection
