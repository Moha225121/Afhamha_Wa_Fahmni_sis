@extends('teacher.layout') @section('title','الطلاب') @section('subtitle','طلاب الصفوف المسندة إليك') @section('content')
<form class="filters">
<input name="q" value="{{ request('q') }}" placeholder="ابحث باسم الطالب...">
<select name="classroom_id"><option value="">كل الصفوف</option>@foreach($classrooms as $c)<option value="{{ $c->id }}" @selected(request('classroom_id')==$c->id)>{{ $c->name }} {{ $c->section }}</option>@endforeach</select>
<button class="btn secondary">بحث</button>
</form>
<div class="table-wrap">
<table>
<thead><tr><th>الرقم</th><th>الطالب</th><th>الصف</th><th>المعدل</th><th>الحالة</th><th></th></tr></thead>
<tbody>
@forelse($students as $s)
<tr>
<td>{{ $s->student_number }}</td>
<td>{{ $s->user->name }}</td>
<td>{{ $s->classroom?->name }} {{ $s->classroom?->section }}</td>
<td>{{ $s->average_percent !== null ? $s->average_percent.'%' : '—' }}</td>
<td><span class="badge {{ $s->status }}">{{ $s->status==='active'?'منتظم':'موقوف' }}</span></td>
<td><a href="{{ route('teacher.students.show',$s) }}">عرض</a></td>
</tr>
@empty
<tr><td colspan="6"><div class="empty">لا يوجد طلاب في صفوفك حاليًا.</div></td></tr>
@endforelse
</tbody>
</table>
</div>
{{ $students->links() }}
@endsection
