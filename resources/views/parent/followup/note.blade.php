@extends('parent.layout')
@section('title', 'تفاصيل الملاحظة')
@section('content')
<section class="page-title"><p>متابعة الطالب</p><h1>تفاصيل الملاحظة</h1></section>
<a class="followup-back" href="{{ route('parent.student-followup', ['student' => $selectedStudent->id]) }}">→ العودة إلى ملاحظات الطالب</a>
<article class="followup-card">
    <div class="followup-labels"><span>ملاحظة {{ $note->typeLabel() }}</span>@if($note->requires_guardian_call || $calls->isNotEmpty())<b class="followup-call-label">استدعاء ولي الأمر</b>@endif</div>
    <h2>{{ $note->title }}</h2>
    <dl class="followup-details"><div><dt>الطالب</dt><dd>{{ $selectedStudent->user->name }}</dd></div><div><dt>المشرف</dt><dd>{{ $note->supervisor?->name ?? 'إدارة المدرسة' }}</dd></div><div><dt>تاريخ الملاحظة</dt><dd>{{ $note->created_at->format('Y-m-d H:i') }}</dd></div></dl>
    <h3>تفاصيل الملاحظة</h3>
    <div class="followup-content">{{ $note->content }}</div>
</article>
@foreach($calls as $call)
    <section class="followup-card">
        <div class="followup-labels"><b class="followup-call-label">استدعاء ولي الأمر</b><span>{{ $call->statusLabel() }}</span></div>
        <h2>استدعاء مرتبط بهذه الملاحظة</h2>
        @if($call->meeting_date)<p><strong>موعد المقابلة:</strong> {{ $call->meeting_date->format('Y-m-d H:i') }}</p>@else<p>يرجى التواصل مع المدرسة لتحديد موعد المقابلة.</p>@endif
        <a class="followup-action" href="{{ route('parent.guardian-calls.show', $call) }}">عرض تفاصيل الاستدعاء <span aria-hidden="true">←</span></a>
    </section>
@endforeach
@endsection
