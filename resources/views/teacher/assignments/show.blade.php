@extends('teacher.layout')
@section('title', $assignment->title)
@section('content')
<section class="page-title"><p>{{ $assignment->subject->name }} · {{ $assignment->classroom->name }}</p><h1>{{ $assignment->title }}</h1></section>
<section class="list-section">
	@forelse($assignment->submissions as $submission)
		<article class="message-card">
			<div class="list-row">
				<div>
					<strong>{{ $submission->student->user->name }}</strong>
					<span>{{ $submission->submitted_at?->format('Y-m-d H:i') ?? 'وقت التسليم غير مسجل' }}</span>
				</div>
				@if($submission->score !== null)
					<b>{{ rtrim(rtrim(number_format((float) $submission->score, 2, '.', ''), '0'), '.') }} / {{ rtrim(rtrim(number_format((float) $assignment->max_score, 2, '.', ''), '0'), '.') }}</b>
				@endif
			</div>
			@if($submission->notes)<p>{{ $submission->notes }}</p>@endif
			@if($submission->fileAttachment?->hasValidPrivateMetadata())
				<a class="action-link" href="{{ route('teacher.submissions.file', $submission) }}">تنزيل {{ $submission->fileAttachment->original_name }}</a>
			@endif
		</article>
	@empty
		<p class="muted-line">لم يستلم هذا الواجب أي تسليم بعد.</p>
	@endforelse
</section>
@endsection
