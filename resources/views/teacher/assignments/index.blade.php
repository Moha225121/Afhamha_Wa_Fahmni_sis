@extends('teacher.layout') @section('title','الواجبات') @section('subtitle','إنشاء الواجبات ومتابعة التسليمات') @section('actions')<a class="btn primary" href="{{ route('teacher.assignments.create') }}">إضافة واجب جديد</a>@endsection @section('content')
<form class="filters" method="get" action="{{ route('teacher.assignments.index') }}">
<label><span class="label-head">الصف</span><select name="classroom_id"><option value="">كل الصفوف</option>@foreach($classrooms as $classroom)<option value="{{ $classroom->id }}" @selected(($filters['classroom_id'] ?? '') == $classroom->id)>{{ $classroom->name }} {{ $classroom->section }}</option>@endforeach</select></label>
<label><span class="label-head">تاريخ التسليم</span><input type="date" name="due_date" value="{{ $filters['due_date'] ?? '' }}"></label>
<button class="btn primary" type="submit">تصفية</button><a class="btn secondary" href="{{ route('teacher.assignments.index') }}">إعادة ضبط</a>
</form>
<div class="table-wrap">
<table>
<thead><tr><th>الواجب</th><th>المادة</th><th>الصف</th><th>التسليم</th><th>التسليمات</th><th>الحالة</th><th></th></tr></thead>
<tbody>
@forelse($rows as $r)
<tr>
<td>{{ $r->title }}</td>
<td>{{ $r->subject }}</td>
<td>{{ $r->classroom }}</td>
<td>{{ \Illuminate\Support\Carbon::parse($r->due_date)->format('Y-m-d') }}</td>
<td>{{ $r->submissions_count }} تسليم / {{ $r->students_total }}</td>
@php($isClosed = $r->due_date && \Illuminate\Support\Carbon::parse($r->due_date)->startOfDay()->lte(today()))
<td><span class="badge {{ $isClosed ? 'inactive' : 'active' }}">{{ $isClosed ? 'مغلق' : 'نشط' }}</span></td>
<td><a href="{{ route('teacher.assignments.edit',$r->id) }}">تعديل</a> · <a href="{{ route('teacher.assignments.submissions',$r->id) }}">التسليمات</a></td>
</tr>
@empty
<tr><td colspan="7"><div class="empty">لا توجد واجبات بعد.<br><a class="btn primary" href="{{ route('teacher.assignments.create') }}">إضافة واجب جديد</a></div></td></tr>
@endforelse
</tbody>
</table>
</div>
{{ $rows->links() }}
@endsection
