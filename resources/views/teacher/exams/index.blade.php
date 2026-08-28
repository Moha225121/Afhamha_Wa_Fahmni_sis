@extends('teacher.layout') @section('title','الاختبارات') @section('subtitle','إعداد الاختبارات ومتابعة النتائج') @section('actions')<a class="btn primary" href="{{ route('teacher.exams.create') }}">إنشاء اختبار</a>@endsection @section('content')
<div class="exam-intro">
    <div>
        <p>أنشئ اختبارًا جديدًا، أو تابع المسودات الحالية، ثم عدّلها قبل موعد التطبيق لضمان تجهيز الاختبار بشكل جيد.</p>
    </div>
</div>
<form class="filters" method="get" action="{{ route('teacher.exams.index') }}">
    <label><span class="label-head">الصف</span><select name="classroom_id"><option value="">كل الصفوف</option>@foreach($classrooms as $classroom)<option value="{{ $classroom->id }}" @selected(($filters['classroom_id'] ?? '') == $classroom->id)>{{ $classroom->name }} {{ $classroom->section }}</option>@endforeach</select></label>
    <label><span class="label-head">تاريخ الاختبار</span><input type="date" name="starts_at" value="{{ $filters['starts_at'] ?? '' }}"></label>
    <button class="btn primary" type="submit">تصفية</button>
    <a class="btn secondary" href="{{ route('teacher.exams.index') }}">إعادة ضبط</a>
</form>
<div class="table-wrap">
<table class="exam-table">
<thead><tr><th>الاختبار</th><th>المادة</th><th>الصف</th><th>التاريخ</th><th>الأسئلة</th><th>الحالة</th><th>الإجراءات</th></tr></thead>
<tbody>
@forelse($rows as $r)
@php
    $startsAt = \Illuminate\Support\Carbon::parse($r->starts_at);
    $isPassed = $startsAt->isPast();
    $statusLabel = 'مسودة';
    $statusClass = 'status-draft';

    if ($r->status === 'completed') {
        $statusLabel = 'مكتمل';
        $statusClass = 'status-complete';
    } elseif ($isPassed && in_array($r->status, ['scheduled', 'published'], true)) {
        $statusLabel = 'منشور';
        $statusClass = 'status-scheduled';
    } elseif ($r->status === 'published') {
        $statusLabel = 'منشور';
        $statusClass = 'status-scheduled';
    } elseif (in_array($r->status, ['scheduled'], true)) {
        $statusLabel = 'مجدول';
        $statusClass = 'status-scheduled';
    }
@endphp
<tr>
<td><div class="exam-title">{{ $r->title }}</div></td>
<td>{{ $r->subject }}</td>
<td>{{ $r->classroom }}</td>
<td>{{ $startsAt->format('Y-m-d') }}</td>
<td>{{ $r->questions_count }}</td>
<td><span class="badge {{ $statusClass }}">{{ $statusLabel }}</span></td>
<td>
    <div class="action-group">
@if($r->status === 'draft')
<a class="action-link" href="{{ route('teacher.exams.edit', $r->id) }}">متابعة</a>
@elseif(
    in_array($r->status, ['scheduled', 'published'], true)
    && $startsAt->isFuture()
)
<a class="action-link" href="{{ route('teacher.exams.edit', $r->id) }}">تعديل</a>
@else
<span class="muted-inline">—</span>
@endif
<form method="post" action="{{ route('teacher.exams.destroy', $r->id) }}">
    @csrf
    @method('delete')
    <button type="submit" class="link-danger teacher-delete-action" data-confirm="هل أنت متأكد أنك تريد حذف هذا الاختبار؟">حذف</button>
</form>
    </div>
</td>
</tr>
@empty
<tr><td colspan="7"><div class="empty">لا توجد اختبارات بعد.<br><a class="btn primary" href="{{ route('teacher.exams.create') }}">إنشاء اختبار</a></div></td></tr>
@endforelse
</tbody>
</table>
</div>
{{ $rows->links() }}
@endsection
