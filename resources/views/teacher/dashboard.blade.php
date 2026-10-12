@extends('teacher.layout')
@section('title', 'لوحة تحكم المعلم')
@section('subtitle', 'ملخص مهامك التعليمية')
@section('content')
<section class="teacher-welcome">
	<div>
		<h2>مرحبًا، {{ $teacher->user?->name ?? auth()->user()->name }}</h2>
		<p>تابع الدروس والاختبارات والواجبات من مكان واحد.</p>
	</div>
	<a class="btn secondary" href="{{ route('teacher.lessons.create') }}">إنشاء درس جديد</a>
</section>
<section class="cards teacher-stats">
	<article class="card stat"><span>الطلاب</span><strong>{{ $stats['students'] }}</strong></article>
	<article class="card stat"><span>اختبارات مسودة</span><strong>{{ $stats['draft_exams'] }}</strong></article>
	<article class="card stat">
		<a href="{{ route('teacher.lessons.index') }}"><span>الدروس</span><strong>{{ $stats['lessons'] }}</strong></a>
	</article>
</section>
<section class="grid-2">
	<div class="panel">
		<h2>الاختبارات القادمة</h2>
		@forelse($upcomingExams as $exam)
			<div class="activity">{{ $exam->title }} — {{ $exam->subject }}</div>
		@empty
			<div class="empty">لا توجد اختبارات قادمة.</div>
		@endforelse
	</div>
	<div class="panel">
		<h2>واجبات قادمة</h2>
		@forelse($upcomingAssignments as $assignment)
			<a class="activity" href="{{ route('teacher.assignments.show', $assignment->id) }}">{{ $assignment->title }} — {{ $assignment->subject }}</a>
		@empty
			<div class="empty">لا توجد واجبات قادمة.</div>
		@endforelse
	</div>
	<div class="panel">
		<h2>الدروس الأخيرة</h2>
		@forelse($recentLessons as $lesson)
			<a class="activity" href="{{ route('teacher.lessons.edit', $lesson->id) }}">{{ $lesson->title }} — {{ $lesson->subject }}</a>
		@empty
			<div class="empty">لا توجد دروس بعد.</div>
		@endforelse
	</div>
</section>
<section class="list-section">
	<div class="section-title"><h2>إعلانات المدرسة</h2><a href="{{ route('teacher.ads.index') }}">عرض الكل</a></div>
	@forelse($announcements as $announcement)
		<article class="message-card"><span>{{ $announcement->published_at?->format('Y-m-d') ?? $announcement->created_at?->format('Y-m-d') }}</span><h3>{{ $announcement->title }}</h3><p>{{ $announcement->content }}</p></article>
	@empty
		<p class="muted-line">لا توجد إعلانات حاليًا.</p>
	@endforelse
</section>
@endsection
