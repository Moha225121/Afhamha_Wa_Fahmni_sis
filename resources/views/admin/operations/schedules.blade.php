@extends('admin.layout')
@section('title','الجداول الدراسية')
@section('actions')<a class="btn primary" href="{{ route('admin.schedules.create') }}">إضافة حصة</a>@endsection
@section('content')
<section class="card"><strong>الاستراحة المعتمدة:</strong> بعد الحصة {{ $breakAfterPeriod }} لمدة {{ $breakDuration }} دقيقة. <a href="{{ route('admin.settings.index') }}">تعديل</a></section>
<form class="filters" method="get"><select name="classroom_id" aria-label="الصف والشعبة"><option value="">كل الصفوف والشعب</option>@foreach($classrooms as $classroom)<option value="{{ $classroom->id }}" @selected(request('classroom_id')==$classroom->id)>{{ $classroom->name }} — {{ $classroom->section ?: 'بدون شعبة' }} — {{ $classroom->academicYear?->name }}</option>@endforeach</select><select name="section" aria-label="الشعبة"><option value="">كل الشعب</option>@foreach($sections as $section)<option value="{{ $section }}" @selected(request('section') === $section)>{{ $section }}</option>@endforeach</select><select name="day_of_week" aria-label="يوم الأسبوع"><option value="">كل الأيام</option>@foreach(['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'] as $number => $day)<option value="{{ $number }}" @selected(request('day_of_week') !== null && request('day_of_week') !== '' && (string) request('day_of_week') === (string) $number)>{{ $day }}</option>@endforeach</select><button class="btn secondary">تصفية</button><a class="btn secondary" href="{{ route('admin.schedules.index') }}">إعادة الضبط</a></form>
<div class="table-wrap"><table><thead><tr><th>اليوم</th><th>الحصة</th><th>الصف</th><th>المادة</th><th>المعلم</th><th>الوقت</th><th>القاعة</th><th></th></tr></thead><tbody>
@forelse($rows as $row)
<tr><td>{{ ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'][$row->day_of_week] }}</td><td>{{ $row->period_number ? 'الحصة '.$row->period_number : '—' }}</td><td>{{ $row->classroom }} — {{ $row->section }}</td><td>{{ $row->subject }}</td><td>{{ $row->teacher }}</td><td>{{ substr($row->starts_at,0,5) }}–{{ substr($row->ends_at,0,5) }}</td><td>{{ $row->room?:'—' }}</td><td><form method="post" action="{{ route('admin.schedules.destroy',$row->id) }}">@csrf @method('delete')<button class="btn secondary" data-confirm="حذف الحصة؟">حذف</button></form></td></tr>
@if((int)$row->period_number===$breakAfterPeriod)
@php
    $breakStart=\Illuminate\Support\Carbon::parse($row->ends_at);
    $breakEnd=$breakStart->copy()->addMinutes($breakDuration);
@endphp
<tr class="schedule-break"><td>{{ ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'][$row->day_of_week] }}</td><td><strong>استراحة</strong></td><td>{{ $row->classroom }} — {{ $row->section }}</td><td colspan="2">استراحة مدرسية</td><td>{{ $breakStart->format('H:i') }}–{{ $breakEnd->format('H:i') }}</td><td colspan="2">{{ $breakDuration }} دقيقة</td></tr>@endif
@empty<tr><td colspan="8"><div class="empty">لا توجد حصص مجدولة.</div></td></tr>@endforelse
</tbody></table></div>{{ $rows->links() }}
@endsection
