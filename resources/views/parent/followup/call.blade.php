@extends('parent.layout')
@section('title', 'تفاصيل استدعاء ولي الأمر')
@section('content')
<section class="page-title"><p>تنبيهات المدرسة</p><h1>تفاصيل استدعاء ولي الأمر</h1></section>
<a class="followup-back" href="{{ route('parent.guardian-calls', ['student' => $selectedStudent->id]) }}">→ العودة إلى الاستدعاءات</a>
<article class="followup-card">
    <div class="followup-labels"><b class="followup-call-label">استدعاء ولي الأمر</b><span>{{ $call->statusLabel() }}</span></div>
    <h2>{{ $call->parentReason() }}</h2>
    <dl class="followup-details">
        <div><dt>الطالب</dt><dd>{{ $selectedStudent->user->name }}</dd></div>
        <div><dt>المشرف</dt><dd>{{ $call->supervisor?->name ?? 'إدارة المدرسة' }}</dd></div>
        <div><dt>تاريخ الاستدعاء</dt><dd>{{ $call->requested_date?->format('Y-m-d') }}</dd></div>
        <div><dt>موعد المقابلة</dt><dd>{{ $call->meeting_date?->format('Y-m-d H:i') ?? 'يرجى التواصل مع المدرسة لتحديد الموعد' }}</dd></div>
        @if($call->resolved_at)<div><dt>تاريخ الإغلاق</dt><dd>{{ $call->resolved_at->format('Y-m-d H:i') }}</dd></div>@endif
    </dl>
    <h3>تفاصيل الاستدعاء</h3>
    <div class="followup-content">{{ $call->parentDetails() ?: 'يرجى مراجعة المدرسة والتواصل مع الإدارة لمتابعة هذا الاستدعاء.' }}</div>
    @if($note)<a class="followup-action" href="{{ route('parent.student-followup.show', $note) }}">عرض الملاحظة المرتبطة <span aria-hidden="true">←</span></a>@endif
</article>
@endsection
