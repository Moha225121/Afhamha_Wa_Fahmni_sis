@extends('teacher.layout') @section('title','الواجبات') @section('subtitle','إنشاء الواجبات ومتابعة التسليمات') @section('actions')<a class="btn primary" href="{{ route('teacher.assignments.create') }}">إضافة واجب جديد</a>@endsection @section('content')
<form class="filters" method="get" action="{{ route('teacher.assignments.index') }}">
<label><span class="label-head">الصف</span><select name="classroom_id"><option value="">كل الصفوف</option>@foreach($classrooms as $classroom)<option value="{{ $classroom->id }}" @selected(($filters['classroom_id'] ?? '') == $classroom->id)>{{ $classroom->name }} {{ $classroom->section }}</option>@endforeach</select></label>
<label><span class="label-head">تاريخ التسليم</span><input type="date" name="due_date" value="{{ $filters['due_date'] ?? '' }}"></label>
<label><span class="label-head">الحالة</span><select name="status"><option value="">كل الحالات</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>نشط</option><option value="closed" @selected(($filters['status'] ?? '') === 'closed')>مغلق</option><option value="completed" @selected(($filters['status'] ?? '') === 'completed')>مكتمل</option><option value="cancelled" @selected(($filters['status'] ?? '') === 'cancelled')>ملغي</option></select></label>
<button class="btn primary" type="submit">تصفية</button><a class="btn secondary" href="{{ route('teacher.assignments.index') }}">إعادة ضبط</a>
</form>
<section class="attendance-summary status-summary" aria-label="ملخص الواجبات"><article><span>نشطة</span><strong>{{ $assignmentSummary['active'] }}</strong></article><article><span>مغلقة</span><strong>{{ $assignmentSummary['closed'] }}</strong></article><article><span>مكتملة</span><strong>{{ $assignmentSummary['completed'] }}</strong></article><article><span>ملغاة</span><strong>{{ $assignmentSummary['cancelled'] }}</strong></article></section>
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
@php($dueAt = $r->due_at ?: $r->due_date)
@php($isClosed = $dueAt && \Illuminate\Support\Carbon::parse($dueAt)->startOfDay()->lte(today()) )
@php($isCompleted = $r->status !== 'cancelled' && $r->students_total > 0 && $r->submissions_count >= $r->students_total)
<td><span class="badge {{ $r->status === 'cancelled' ? 'status-cancelled' : ($isCompleted ? 'status-complete' : ($isClosed ? 'inactive' : 'active')) }}">{{ $r->status === 'cancelled' ? 'ملغي' : ($isCompleted ? 'مكتمل' : ($isClosed ? 'مغلق' : 'نشط')) }}</span></td>
<td><a href="{{ route('teacher.assignments.edit',$r->id) }}">تعديل</a> · <a href="{{ route('teacher.assignments.submissions',$r->id) }}">التسليمات</a>@if($r->status !== 'cancelled' && $isClosed) · <form method="post" action="{{ route('teacher.assignments.cancel',$r->id) }}" style="display:inline">@csrf @method('patch')<button class="teacher-delete-action" type="submit" title="إلغاء الواجب" aria-label="إلغاء الواجب" data-confirm="هل أنت متأكد من إلغاء هذا الواجب؟">إلغاء</button></form>@endif · <form method="post" action="{{ route('teacher.assignments.destroy',$r->id) }}" style="display:inline">@csrf @method('delete')<button class="delete-column-btn teacher-delete-action" type="submit" title="حذف الواجب" aria-label="حذف الواجب" data-confirm="هل أنت متأكد من حذف هذا الواجب؟">×</button></form></td>
</tr>
@empty
<tr><td colspan="7"><div class="empty">لا توجد واجبات بعد.<br><a class="btn primary" href="{{ route('teacher.assignments.create') }}">إضافة واجب جديد</a></div></td></tr>
@endforelse
</tbody>
</table>
</div>
{{ $rows->links() }}
@endsection
