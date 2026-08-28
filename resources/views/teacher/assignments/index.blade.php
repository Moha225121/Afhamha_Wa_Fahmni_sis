@extends('teacher.layout') @section('title','الواجبات') @section('subtitle','إنشاء الواجبات ومتابعة التسليمات') @section('actions')<a class="btn primary" href="{{ route('teacher.assignments.create') }}">إضافة واجب جديد</a>@endsection @section('content')
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
<td><span class="badge {{ $r->status==='active'?'active':'inactive' }}">{{ $r->status==='active'?'نشط':'مغلق' }}</span></td>
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
