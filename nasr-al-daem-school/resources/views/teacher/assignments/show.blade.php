@extends('teacher.layout')
@section('title', $assignment->title)
@section('content')
<section class="page-title"><p>{{ $assignment->subject->name }} · {{ $assignment->classroom->name }}</p><h1>{{ $assignment->title }}</h1></section>
<section class="list-section">@forelse($assignment->submissions as $submission)<div class="list-row"><div><strong>{{ $submission->student->user->name }}</strong><span>{{ $submission->submitted_at?->format('Y-m-d H:i') ?? 'وقت التسليم غير مسجل' }}@if($submission->fileAttachment?->hasValidPrivateMetadata()) · {{ $submission->fileAttachment->original_name }}@endif</span></div>@if($submission->fileAttachment?->hasValidPrivateMetadata())<a class="action-link" href="{{ route('teacher.submissions.file', $submission) }}">تنزيل</a>@endif</div>@empty<p class="muted-line">لم يستلم هذا الواجب أي تسليم بعد.</p>@endforelse</section>
@endsection
