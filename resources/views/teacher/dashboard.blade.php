@extends('teacher.layout') @section('title','لوحة تحكم المعلم') @section('subtitle','مؤشرات صفوفك المباشرة من قاعدة البيانات') @section('content')
<section class="cards">
@foreach(['classrooms'=>'صفوفي','students'=>'طلابي','today_attendance'=>'حضور اليوم','draft_exams'=>'اختبارات مسودة','active_assignments'=>'واجبات نشطة'] as $key=>$label)
<article class="card stat"><span>{{ $label }}</span><strong>{{ $stats[$key] }}</strong></article>
@endforeach
</section>
<section class="grid-2">
<div class="panel"><h2>الاختبارات القادمة</h2>
@forelse($upcomingExams as $e)
<div class="activity">{{ $e->title }} — {{ $e->subject }} · {{ $e->classroom }}<small>{{ \Illuminate\Support\Carbon::parse($e->starts_at)->format('Y-m-d H:i') }}</small></div>
@empty
<div class="empty">لا توجد اختبارات قادمة.</div>
@endforelse
</div>
<div class="panel"><h2>واجبات قادمة</h2>
@forelse($upcomingAssignments as $a)
<div class="activity">{{ $a->title }} — {{ $a->subject }} · {{ $a->classroom }}<small>موعد التسليم: {{ $a->due_date }}</small></div>
@empty
<div class="empty">لا توجد واجبات قادمة.</div>
@endforelse
</div>
</section>
@endsection
