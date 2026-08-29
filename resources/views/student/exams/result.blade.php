@extends('student.layout')
@section('title', 'نتيجة الاختبار')
@section('content')
<section class="page-title"><p>{{ $attempt->exam->subject->name }}</p><h1>{{ $attempt->exam->title }}</h1></section>
@if(session('success'))<div class="notice success">{{ session('success') }}</div>@endif
@php($score = rtrim(rtrim(number_format((float) $attempt->score, 2, '.', ''), '0'), '.'))
@php($maximum = rtrim(rtrim(number_format((float) $attempt->maximum_score, 2, '.', ''), '0'), '.'))
@php($percentage = rtrim(rtrim(number_format((float) $attempt->percentage, 2, '.', ''), '0'), '.'))
@if($attempt->status === 'pending_review')<div class="notice">أُرسلت المحاولة وتنتظر مراجعة الأسئلة غير الموضوعية.</div>@else<section class="metrics-grid"><article class="metric"><span>الدرجة</span><strong>{{ $score }} / {{ $maximum }}</strong></article><article class="metric"><span>النسبة</span><strong>{{ $percentage }}%</strong></article></section>@endif
<section class="info-list section-space"><div><span>الحالة</span><strong>{{ $attempt->status === 'pending_review' ? 'بانتظار المراجعة' : 'تم الإرسال والتصحيح' }}</strong></div><div><span>بدأت في</span><strong>{{ $attempt->started_at->format('Y-m-d H:i') }}</strong></div><div><span>أُرسلت في</span><strong>{{ $attempt->submitted_at?->format('Y-m-d H:i') }}</strong></div>@if($attempt->graded_at)<div><span>صُححت في</span><strong>{{ $attempt->graded_at->format('Y-m-d H:i') }}</strong></div>@endif</section>
@endsection
