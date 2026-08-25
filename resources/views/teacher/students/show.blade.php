@extends('teacher.layout') @section('title',$student->user->name) @section('subtitle','ملف الطالب الأكاديمي') @section('content')
<div class="panel">
<h2>{{ $student->user->name }}</h2>
<p class="muted">{{ $student->student_number }} · {{ $student->classroom?->name }} {{ $student->classroom?->section }}</p>
</div>
<section class="cards" style="grid-template-columns:repeat(3,1fr);margin-top:18px">
<article class="card stat"><span>الواجبات (تسليم)</span><strong>{{ $assignmentsSubmitted }} / {{ $assignmentsTotal }}</strong></article>
<article class="card stat"><span>الحضور</span><strong>{{ $attendanceRate !== null ? $attendanceRate.'%' : '—' }}</strong></article>
<article class="card stat"><span>المعدل</span><strong>{{ $averagePercent !== null ? $averagePercent.'%' : '—' }}</strong></article>
</section>
<div class="panel" style="margin-top:18px">
<h2>آخر النتائج</h2>
<div class="table-wrap">
<table>
<thead><tr><th>التقييم</th><th>الدرجة</th><th>التاريخ</th></tr></thead>
<tbody>
@forelse($recentResults as $r)
<tr>
<td>{{ $r->label }} <span class="badge">{{ $r->kind==='exam'?'اختبار':'واجب' }}</span></td>
<td>{{ rtrim(rtrim(number_format($r->score,2),'0'),'.') }}/{{ rtrim(rtrim(number_format($r->max_score,2),'0'),'.') }}</td>
<td>{{ \Illuminate\Support\Carbon::parse($r->date)->format('Y-m-d') }}</td>
</tr>
@empty
<tr><td colspan="3"><div class="empty">لا توجد نتائج مسجلة بعد.</div></td></tr>
@endforelse
</tbody>
</table>
</div>
</div>
@endsection
