@extends('teacher.layout') @section('title','إدارة الحضور') @section('subtitle','سجل حضور طلاب صفوفك') @section('content')
<form class="filters" method="get" action="{{ route('teacher.attendance.index') }}">
<input type="date" name="date" value="{{ $date }}">
<select name="classroom_id"><option value="">اختر الصف</option>@foreach($classrooms as $c)<option value="{{ $c->id }}" @selected($classroomId==$c->id)>{{ $c->name }} {{ $c->section }}</option>@endforeach</select>
<button class="btn secondary" type="submit">عرض</button>
</form>
<form method="post" action="{{ route('teacher.attendance.store') }}">
@csrf
<input type="hidden" name="date" value="{{ $date }}">
<input type="hidden" name="classroom_id" value="{{ $classroomId }}">
<div class="table-wrap">
<table>
<thead><tr><th>الطالب</th><th>الرقم</th><th>الحالة</th></tr></thead>
<tbody>
@forelse($students as $s)
<tr>
<td>{{ $s->user->name }}</td>
<td>{{ $s->student_number }}</td>
<td>
	<input type="hidden" name="records[{{ $s->id }}]" value="{{ $records[$s->id]->status ?? 'present' }}" class="attendance-input" data-student-id="{{ $s->id }}">
	<div class="attendance-buttons" data-student-id="{{ $s->id }}" data-status="{{ $records[$s->id]->status ?? 'present' }}">
		<button type="button" class="att-btn" data-value="present"><span class="icon">✓</span><span class="label">حاضر</span></button>
		<button type="button" class="att-btn" data-value="absent"><span class="icon">✕</span><span class="label">غائب</span></button>
		<button type="button" class="att-btn" data-value="late"><span class="icon">⏱</span><span class="label">متأخر</span></button>
		<button type="button" class="att-btn" data-value="excused"><span class="icon">⚑</span><span class="label">بعذر</span></button>
	</div>
</td>
</tr>
@empty
<tr><td colspan="3"><div class="empty">اختر صفًا يحتوي على طلاب.</div></td></tr>
@endforelse
</tbody>
</table>
</div>
@if($students->isNotEmpty())<button class="btn primary save-attendance">حفظ الحضور</button>@endif
</form>
@endsection

@section('scripts')
<style>
	.attendance-buttons { display:flex; gap:8px; justify-content:center; flex-wrap:wrap }
	.att-btn { display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border-radius:8px; border:1px solid #d1d5db; background:#fff; cursor:pointer; font-size:16px; min-width:96px; transition:all .12s ease; box-shadow:0 1px 0 rgba(16,24,40,0.03); }
	.att-btn .icon { font-size:14px; opacity:0.95 }
	.att-btn .label { line-height:1; }
	.att-btn:hover { transform:translateY(-1px); box-shadow:0 6px 18px rgba(15,23,42,0.06); }
	.att-btn:focus { outline:3px solid rgba(59,130,246,0.18); }
	.att-btn.selected { color:#fff; }
	.att-btn[data-value="present"].selected { background:#16a34a; border-color:#16a34a }
	.att-btn[data-value="absent"].selected { background:#ef4444; border-color:#ef4444 }
	.att-btn[data-value="late"].selected { background:#f59e0b; border-color:#f59e0b }
	.att-btn[data-value="excused"].selected { background:#3b82f6; border-color:#3b82f6 }
</style>
<script>
document.querySelectorAll('.attendance-buttons').forEach(function(container){
	var studentId = container.dataset.studentId;
	var hidden = document.querySelector('.attendance-input[data-student-id="' + studentId + '"]');
	var initial = container.dataset.status || (hidden && hidden.value) || 'present';

	function setSelected(value){
		container.querySelectorAll('.att-btn').forEach(function(b){
			b.classList.toggle('selected', b.dataset.value === value);
		});
		if (hidden) hidden.value = value;
	}

	// initialize
	setSelected(initial);

	container.addEventListener('click', function(e){
		var btn = e.target.closest('.att-btn');
		if (!btn) return;
		var v = btn.dataset.value;
		setSelected(v);
	});
});
</script>
@endsection
