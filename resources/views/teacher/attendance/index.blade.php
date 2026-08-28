@extends('teacher.layout') @section('title','إدارة الحضور') @section('subtitle','سجل حضور طلاب صفوفك') @section('content')
<form class="filters" method="get" action="{{ route('teacher.attendance.index') }}">
<label><span class="label-head">التاريخ</span><input type="date" name="date" value="{{ $date }}"></label>
<label><span class="label-head">الصف</span><select name="classroom_id"><option value="">كل الصفوف</option>@foreach($classrooms as $c)<option value="{{ $c->id }}" @selected($classroomId==$c->id)>{{ $c->name }} {{ $c->section }}</option>@endforeach</select></label>
<button class="btn primary" type="submit">عرض السجل</button>
</form>
<section class="attendance-summary" aria-label="ملخص الحضور"><article><span>حاضر</span><strong>{{ $attendanceSummary['present'] }}</strong></article><article><span>غائب</span><strong>{{ $attendanceSummary['absent'] }}</strong></article><article><span>متأخر</span><strong>{{ $attendanceSummary['late'] }}</strong></article><article><span>بعذر</span><strong>{{ $attendanceSummary['excused'] }}</strong></article></section>
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
	.attendance-buttons { display:flex; column-gap:12px; row-gap:12px; justify-content:center; flex-wrap:wrap; }
	.att-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-width:104px; min-height:46px; padding:10px 14px; border:1px solid #dfe7f2; border-radius:12px; background:#fff; color:#52617c; cursor:pointer; font-size:14px; font-weight:800; transition:transform .16s ease, box-shadow .16s ease, border-color .16s ease, background-color .16s ease, color .16s ease; box-shadow:0 3px 9px rgba(18,42,77,.05); }
	.att-btn .icon { display:grid; width:24px; height:24px; place-items:center; border-radius:8px; background:#f1f5f9; color:#64748b; font-size:13px; font-weight:900; }
	.att-btn .label { line-height:1; }
	.att-btn:hover { transform:translateY(-2px); border-color:#b9cde5; box-shadow:0 8px 18px rgba(18,42,77,.10); }
	.att-btn:focus-visible { outline:3px solid rgba(59,130,246,.22); outline-offset:2px; }
	.att-btn.selected { color:#fff; box-shadow:0 8px 18px rgba(18,42,77,.14); transform:translateY(-1px); }
	.att-btn.selected .icon { background:rgba(255,255,255,.2); color:#fff; }
	.att-btn[data-value="present"].selected { background:#16805d; border-color:#16805d; }
	.att-btn[data-value="absent"].selected { background:#c23b32; border-color:#c23b32; }
	.att-btn[data-value="late"].selected { background:#b77911; border-color:#b77911; }
	.att-btn[data-value="excused"].selected { background:#3157b2; border-color:#3157b2; }
	@media (max-width:600px) { .attendance-buttons { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; } .att-btn { width:100%; min-width:0; } }
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
