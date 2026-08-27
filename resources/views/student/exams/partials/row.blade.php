@php($attempt = $exam->attempts->first())
<div class="list-row"><div><strong>{{ $exam->title }}</strong><span>{{ $exam->subject->name }} · {{ $exam->starts_at->format('Y-m-d H:i') }} · {{ $exam->duration_minutes }} دقيقة</span></div><div>
@if($attempt && $attempt->status === 'in_progress' && $group === 'available')<a class="action-link" href="{{ route('student.exams.attempt', $attempt) }}">متابعة</a>
@elseif($group === 'available' && $exam->has_remaining_attempts)<form method="post" action="{{ route('student.exams.start', $exam) }}">@csrf<button>{{ $attempt ? 'ابدأ محاولة جديدة' : 'ابدأ' }}</button></form>
@elseif($attempt && $attempt->status !== 'in_progress')<a class="action-link" href="{{ route('student.exams.result', $attempt) }}">النتيجة</a>
@elseif($attempt)<a class="action-link" href="{{ route('student.exams.result', $attempt) }}">إنهاء وعرض النتيجة</a>
@elseif($group === 'upcoming')<b>قادم</b>@else<b>لم يُنفذ</b>@endif
</div></div>
