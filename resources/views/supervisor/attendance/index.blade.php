@extends('supervisor.layout')
@section('title','حضور اليوم')
@section('content')
<form class="filters" method="get"><input type="date" name="date" value="{{ $date }}"><select name="classroom_id">@foreach($classes as $classroom)<option value="{{ $classroom->id }}" @selected($classId===$classroom->id)>{{ $classroom->name }} {{ $classroom->section }}</option>@endforeach</select><button>عرض</button></form>
@if($classId)
<form method="post" action="{{ route('supervisor.attendance.store') }}" enctype="multipart/form-data">@csrf<input type="hidden" name="date" value="{{ $date }}"><input type="hidden" name="classroom_id" value="{{ $classId }}"><div class="table-wrap"><table><thead><tr><th>الطالب</th><th>الحالة</th><th>وقت الوصول</th><th>دقائق التأخير</th><th>سبب العذر</th><th>ملاحظات</th></tr></thead><tbody>
@foreach($students as $student)
@php
    $record=$records->get($student->id);
    $status=$record?->status?->value??'present';
@endphp
<tr><td><a href="{{ route('supervisor.students.show',$student) }}">{{ $student->user->name }}</a></td><td><select name="records[{{ $student->id }}][status]" data-status><option value="present" @selected($status==='present')>حاضر</option><option value="absent" @selected($status==='absent')>غائب</option><option value="late" @selected($status==='late')>متأخر</option><option value="excused_absence" @selected($status==='excused_absence')>غياب بعذر</option><option value="excused_late" @selected($status==='excused_late')>تأخير بعذر</option></select></td><td><input type="time" name="records[{{ $student->id }}][arrival_time]" value="{{ $record?->arrival_time }}"></td><td><input type="number" min="0" name="records[{{ $student->id }}][late_minutes]" value="{{ $record?->late_minutes }}"></td><td><input name="records[{{ $student->id }}][excuse_reason]" value="{{ $record?->excuse_reason }}"><input type="file" name="records[{{ $student->id }}][excuse_document]" accept=".pdf,.jpg,.jpeg,.png"></td><td><input name="records[{{ $student->id }}][notes]" value="{{ $record?->notes }}"></td></tr>
@endforeach
</tbody></table></div><button class="btn primary">حفظ حضور اليوم</button></form>
@else<div class="empty">لا توجد فصول مكلف بها. يرجى مراجعة الإدارة لتحديد فصل للمشرف.</div>@endif
@endsection
