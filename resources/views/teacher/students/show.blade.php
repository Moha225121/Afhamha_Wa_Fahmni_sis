@extends('teacher.layout') @section('title',$student->user->name) @section('subtitle','ملف الطالب الأكاديمي') @section('content')
<div class="panel teacher-student-profile">
<div class="teacher-student-profile__identity"><span class="teacher-student-profile__avatar">{{ mb_substr($student->user->name, 0, 1) }}</span><div><span class="eyebrow">ملف الطالب الأكاديمي</span><h2>{{ $student->user->name }}</h2><p class="muted">{{ $student->student_number }} · {{ $student->classroom?->name }} {{ $student->classroom?->section }}</p></div><button type="button" class="btn secondary teacher-note-toggle" aria-expanded="false" aria-controls="teacher-note-form">إرسال ملاحظة</button></div>
<form method="post" action="{{ route('teacher.students.notes.store', $student) }}" class="teacher-note-form" id="teacher-note-form" hidden>@csrf<label><span class="label-head">ملاحظة للطالب</span><textarea name="body" rows="3" required maxlength="2000" placeholder="اكتب ملاحظة تشجيعية أو توجيهًا للطالب..."></textarea></label><div class="teacher-note-actions"><button class="btn primary" type="submit">حفظ وإرسال</button><button class="btn secondary teacher-note-cancel" type="button">إلغاء</button></div></form>
</div>
<section class="cards" style="grid-template-columns:repeat(3,1fr);margin-top:18px">
<article class="card stat"><span>الواجبات (تسليم)</span><strong>{{ $assignmentsSubmitted }} / {{ $assignmentsTotal }}</strong></article>
<article class="card stat"><span>الحضور</span><strong>{{ $attendanceRate !== null ? $attendanceRate.'%' : '—' }}</strong></article>
<article class="card stat"><span>متوسط المادة</span><strong>{{ $averagePercent !== null ? $averagePercent.'%' : '—' }}</strong><small>{{ $subjectLabel ?: 'المادة المسندة' }}</small></article>
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
<section class="panel teacher-notes-panel"><div class="panel-heading"><div><span class="eyebrow">التواصل</span><h2>آخر الملاحظات</h2></div></div>@forelse($notes as $note)<div class="teacher-note"><p>{{ $note->body }}</p><small>{{ $note->created_at->format('Y-m-d H:i') }}</small></div>@empty<p class="muted">لا توجد ملاحظات مرسلة لهذا الطالب بعد.</p>@endforelse</section>
@endsection
@section('scripts')
<script>
const noteToggle = document.querySelector('.teacher-note-toggle');
const noteForm = document.getElementById('teacher-note-form');
const noteCancel = document.querySelector('.teacher-note-cancel');
noteToggle?.addEventListener('click', () => { const open = noteForm.hidden; noteForm.hidden = !open; noteToggle.setAttribute('aria-expanded', String(open)); if (open) noteForm.querySelector('textarea')?.focus(); });
noteCancel?.addEventListener('click', () => { noteForm.reset(); noteForm.hidden = true; noteToggle.setAttribute('aria-expanded', 'false'); });
</script>
@endsection
