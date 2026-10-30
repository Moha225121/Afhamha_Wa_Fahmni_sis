@extends('admin.layout')
@section('title', 'مركز التقارير')
@section('actions')<a class="btn primary" href="{{ route('admin.reports.pdf', request()->query()) }}">تنزيل التقرير PDF</a>@endsection
@section('content')
<form class="filters" method="get" action="{{ route('admin.reports.index') }}">
	<label>من تاريخ<input type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
	<label>إلى تاريخ<input type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
	<label>الصف<select name="classroom_id"><option value="">كل الصفوف</option>@foreach($classrooms as $classroom)<option value="{{ $classroom->id }}" @selected(($filters['classroom_id'] ?? '') == $classroom->id)>{{ $classroom->name }} {{ $classroom->section }}</option>@endforeach</select></label>
	<label>حالة الاختبار<select name="exam_status"><option value="scheduled" @selected($examStatus === 'scheduled')>مجدول (افتراضي)</option><option value="published" @selected($examStatus === 'published')>منشور</option><option value="completed" @selected($examStatus === 'completed')>مكتمل</option><option value="cancelled" @selected($examStatus === 'cancelled')>ملغي</option></select></label>
	<button class="btn secondary" type="submit">تطبيق التصفية</button>
	<a class="btn secondary" href="{{ route('admin.reports.index') }}">إعادة ضبط</a>
</form>
<p class="muted">الحضور والنتائج حسب تاريخ التسجيل، والاختبارات حسب الموعد والحالة المحددة. يبدأ التقرير بالاختبارات المجدولة؛ المسودات لا تظهر هنا. مؤشرات الدرجات تقتصر على النتائج المنشورة.</p>
<div class="cards">
	<article class="card"><h2>الطلاب حسب الحالة</h2>@forelse($students as $row)<p>{{ $row->status }}: <b>{{ $row->total }}</b></p>@empty<p>لا توجد سجلات.</p>@endforelse</article>
	<article class="card"><h2>المعلمون حسب الحالة</h2>@forelse($teachers as $row)<p>{{ $row->status }}: <b>{{ $row->total }}</b></p>@empty<p>لا توجد سجلات.</p>@endforelse</article>
	<article class="card"><h2>الحضور المسجل</h2>@forelse($attendance as $row)<p>{{ $row->status }}: <b>{{ $row->total }}</b></p>@empty<p>لا توجد سجلات ضمن المدة.</p>@endforelse</article>
	<article class="card"><h2>النتائج المنشورة</h2><p>عدد الدرجات: <b>{{ $results->total }}</b></p><p>متوسط النسب: <b>{{ $results->average === null ? '—' : number_format($results->average, 2).'%' }}</b></p><p>الأعلى: {{ $results->highest === null ? '—' : number_format($results->highest, 2).'%' }}</p><p>الأدنى: {{ $results->lowest === null ? '—' : number_format($results->lowest, 2).'%' }}</p></article>
</div>
<section class="section-gap">
	<h2>الاختبارات {{ ['scheduled' => 'المجدولة', 'published' => 'المنشورة', 'completed' => 'المكتملة', 'cancelled' => 'الملغاة'][$examStatus] ?? '' }}</h2>
	<div class="table-wrap"><table><thead><tr><th>الاختبار</th><th>المادة</th><th>الصف</th><th>الموعد</th><th>الحالة</th><th>الأسئلة</th></tr></thead><tbody>
	@forelse($exams as $exam)<tr><td>{{ $exam->title }}</td><td>{{ $exam->subject }}</td><td>{{ $exam->classroom }} {{ $exam->section }}</td><td>{{ \Illuminate\Support\Carbon::parse($exam->starts_at)->format('Y-m-d H:i') }}</td><td>{{ ['draft'=>'مسودة','scheduled'=>'مجدول','published'=>'منشور','completed'=>'مكتمل','cancelled'=>'ملغي'][$exam->status] ?? $exam->status }}</td><td>{{ $exam->questions_count }}</td></tr>@empty<tr><td colspan="6">لا توجد اختبارات ضمن الفلاتر الحالية.</td></tr>@endforelse
	</tbody></table></div>
</section>
<p class="muted">وقت إنشاء التقرير: {{ $generatedAt->format('Y-m-d H:i') }}</p>
@endsection
