@extends('teacher.layout')
@section('title', 'تصحيح الاختبار: '.$exam->title)
@section('content')
<p class="muted-line">تعرض هذه الصفحة المحاولات المسلّمة فقط. تبقى درجات الاختبار غير منشورة إلى أن تراجعها الإدارة وتعتمدها وتنشرها من إدارة النتائج.</p>
<p class="muted-line">إذا استوردت الإدارة درجات الاختبار إلى مسودة النتائج قبل تعديل التصحيح، يلزم إعادة استيراد الاختبار لتحديث درجات المسودة.</p>
@if($lockedForPublication)<p class="notice">التصحيح مقفل لأن النتائج قيد المراجعة أو منشورة. يلزم إعادة فترة النتائج إلى المسودة قبل التعديل.</p>@endif
@forelse($attempts as $attempt)
<section class="panel">
    <h2>{{ $attempt->student->user->name }} · المحاولة {{ $attempt->attempt_number }}</h2>
    <p>{{ $attempt->status === 'pending_review' ? 'بانتظار التصحيح' : 'مصححة' }} · {{ $attempt->score }} / {{ $attempt->maximum_score }}</p>
    <form method="post" action="{{ route('teacher.exams.attempts.store', [$exam, $attempt]) }}">
        @csrf
        @foreach($attempt->answers as $answer)
            <div class="field">
                <strong>السؤال {{ $loop->iteration }}</strong><x-assignment-content :content="$answer->question_text_snapshot" />
                <p dir="auto">{{ $answer->answerOptions()[$answer->answer] ?? $answer->answer ?? 'لم يجب الطالب' }}</p>
                @if(in_array($answer->question_type_snapshot, ['multiple_choice', 'true_false'], true))
                    <p>التصحيح الآلي: {{ $answer->awarded_score }} / {{ $answer->max_score }}</p>
                @else
                    <label for="answer-score-{{ $answer->id }}">الدرجة من {{ $answer->max_score }}</label>
                    <input id="answer-score-{{ $answer->id }}" type="number" name="scores[{{ $answer->id }}]" min="0" max="{{ $answer->max_score }}" step="0.01" required value="{{ old('scores.'.$answer->id, $answer->awarded_score) }}" @disabled($lockedForPublication)>
                @endif
            </div>
        @endforeach
        @if(!$lockedForPublication && $attempt->answers->whereNotIn('question_type_snapshot', ['multiple_choice', 'true_false'])->isNotEmpty())<button class="btn primary" type="submit">حفظ التصحيح</button>@endif
    </form>
</section>
@empty
<div class="empty">لا توجد محاولات مسلّمة لهذا الاختبار.</div>
@endforelse
{{ $attempts->links() }}
@endsection
