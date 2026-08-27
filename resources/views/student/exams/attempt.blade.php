@extends('student.layout')
@section('title', $attempt->exam->title)
@section('content')
<section class="page-title"><p>محاولة جارية</p><h1>{{ $attempt->exam->title }}</h1></section><div class="notice" id="timer" data-expires="{{ $attempt->expires_at->toIso8601String() }}" data-server-now="{{ now()->toIso8601String() }}">الوقت المتبقي يحسب من الخادم</div>
@if(session('success')) <div class="notice success">{{ session('success') }}</div> @endif
@foreach($attempt->answers as $response)
<form class="profile-form question-form" method="post" action="{{ route('student.exams.answers.save', [$attempt, $response->question]) }}">@csrf<strong>{{ $loop->iteration }}. {{ $response->question_text_snapshot }} ({{ $response->max_score }} درجة)</strong>
@if(in_array($response->question_type_snapshot, ['multiple_choice','true_false'])) @foreach($response->answerOptions() as $value => $label)<label class="choice"><input type="radio" name="answer" value="{{ $value }}" @checked((string)$response->answer === (string)$value)> {{ $label }}</label>@endforeach @else <textarea name="answer" rows="4">{{ $response->answer }}</textarea> @endif<button type="submit">حفظ الإجابة</button></form>
@endforeach
<form method="post" action="{{ route('student.exams.submit', $attempt) }}" onsubmit="return confirm('هل تريد إرسال الاختبار نهائيًا؟')">@csrf<button class="final-submit">إرسال الاختبار</button></form>
<script>const timer=document.getElementById('timer');const initial=Math.max(0,new Date(timer.dataset.expires)-new Date(timer.dataset.serverNow));const deadline=performance.now()+initial;function tick(){const s=Math.max(0,Math.ceil((deadline-performance.now())/1000));timer.textContent=`الوقت المتبقي: ${Math.floor(s/60)}:${String(s%60).padStart(2,'0')}`;if(s===0)location.reload()}tick();setInterval(tick,1000);</script>
@endsection
