@extends('teacher.layout') @section('title', $assignment->title) @section('subtitle','تتبع التسليمات وإدخال الدرجات') @section('content')
<form method="post" action="{{ route('teacher.assignments.submissions.store',$assignment->id) }}">
@csrf
<div class="table-wrap">
<table>
<thead><tr><th>الطالب</th><th>الرقم</th><th>تم التسليم</th><th>الدرجة / {{ rtrim(rtrim(number_format($assignment->max_score,2),'0'),'.') }}</th></tr></thead>
<tbody>
@forelse($students as $s)
@php($sub = $submissions[$s->id] ?? null)
<tr>
<td>{{ $s->user->name }}</td>
<td>{{ $s->student_number }}</td>
<td><input type="checkbox" name="submitted[{{ $s->id }}]" value="1" @checked($sub && $sub->submitted_at)></td>
<td><input type="number" step="0.25" min="0" max="{{ $assignment->max_score }}" name="scores[{{ $s->id }}]" value="{{ $sub->score ?? '' }}"></td>
</tr>
@empty
<tr><td colspan="4"><div class="empty">لا يوجد طلاب في هذا الصف.</div></td></tr>
@endforelse
</tbody>
</table>
</div>
@if($students->isNotEmpty())<button class="btn primary">حفظ</button>@endif
</form>
@endsection
