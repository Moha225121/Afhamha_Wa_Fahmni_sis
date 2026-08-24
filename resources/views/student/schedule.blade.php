@extends('student.layout')
@section('title','الجدول الدراسي')
@section('content')
<section class="page-title"><p>{{ $student->classroom?->name }}</p><h1>الجدول الدراسي</h1></section><section class="list-section">@forelse($schedule as $item)<div class="list-row"><div><strong>{{ ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'][$item->day_of_week] ?? $item->day_of_week }} · {{ $item->subject }}</strong><span>{{ substr($item->starts_at,0,5) }} - {{ substr($item->ends_at,0,5) }} · {{ $item->teacher }} · {{ $item->room }}</span></div></div>@empty<p class="muted-line">لم ينشر جدول لهذا الصف.</p>@endforelse</section>
@endsection
