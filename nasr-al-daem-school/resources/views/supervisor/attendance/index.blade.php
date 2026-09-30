@extends('supervisor.layout')
@section('title','حضور اليوم')
@section('content')
<form class="filters" method="get"><input type="date" name="date" value="{{ $date }}"><select name="classroom_id">@foreach($classes as $classroom)<option value="{{ $classroom->id }}" @selected($classId===$classroom->id)>{{ $classroom->name }} {{ $classroom->section }}</option>@endforeach</select><button>عرض</button></form>
@if($classId)
<section class="panel" style="margin:16px 0"><h2>تسجيل سريع عبر بطاقة الطالب</h2><p class="muted-line">امسح QR أو الصق رابط البطاقة/الرقم الدراسي لتسجيل الحضور فورًا.</p><input id="qr-value" type="text" placeholder="رابط QR أو الرقم الدراسي"><button type="button" class="btn primary" id="submit-qr">تسجيل حاضر</button><div id="qr-result" role="status"></div></section>
<form method="post" action="{{ route('supervisor.attendance.store') }}" enctype="multipart/form-data">@csrf<input type="hidden" name="date" value="{{ $date }}"><input type="hidden" name="classroom_id" value="{{ $classId }}"><div class="table-wrap"><table><thead><tr><th>الطالب</th><th>الحالة</th><th>وقت الوصول</th><th>دقائق التأخير</th><th>سبب العذر</th><th>ملاحظات</th></tr></thead><tbody>
@foreach($students as $student)
@php
    $record=$records->get($student->id);
    $status=$record?->status?->value??'present';
@endphp
<tr><td><a href="{{ route('supervisor.students.show',$student) }}">{{ $student->user->name }}</a></td><td><select name="records[{{ $student->id }}][status]" data-status><option value="present" @selected($status==='present')>حاضر</option><option value="absent" @selected($status==='absent')>غائب</option><option value="late" @selected($status==='late')>متأخر</option><option value="excused_absence" @selected($status==='excused_absence')>غياب بعذر</option><option value="excused_late" @selected($status==='excused_late')>تأخير بعذر</option></select></td><td><input type="time" name="records[{{ $student->id }}][arrival_time]" value="{{ $record?->arrival_time }}"></td><td><input type="number" min="0" name="records[{{ $student->id }}][late_minutes]" value="{{ $record?->late_minutes }}"></td><td><input name="records[{{ $student->id }}][excuse_reason]" value="{{ $record?->excuse_reason }}"><input type="file" name="records[{{ $student->id }}][excuse_document]" accept=".pdf,.jpg,.jpeg,.png"></td><td><input name="records[{{ $student->id }}][notes]" value="{{ $record?->notes }}"></td></tr>
@endforeach
</tbody></table></div><button class="btn primary">حفظ حضور اليوم</button></form>
<script>(()=>{const i=document.getElementById('qr-value'),o=document.getElementById('qr-result');const p=v=>{const m=String(v||'').match(/student-card\/(\d+)\/verify|^(\d+)$/);return m?Number(m[1]||m[2]):null};document.getElementById('submit-qr').onclick=async()=>{const id=p(i.value);if(!id){o.textContent='أدخل رابط QR صالحًا أو الرقم الدراسي.';return}const r=await fetch('{{ route('supervisor.attendance.scan') }}',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}','Accept':'application/json'},body:JSON.stringify({student_id:id,classroom_id:{{ $classId }},date:'{{ $date }}',status:'present'})});const d=await r.json();o.textContent=r.ok?'✓ '+d.message+' '+d.student.name:(d.message||'تعذر التسجيل');i.value=''}})();</script>
@else<div class="empty">لا توجد فصول مكلف بها. يرجى مراجعة الإدارة لتحديد فصل للمشرف.</div>@endif
@endsection
