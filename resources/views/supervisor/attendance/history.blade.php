@extends('supervisor.layout')
@section('title', 'تقارير الحضور')
@section('content')
<link rel="stylesheet" href="{{ asset('css/supervisor-reports.css') }}?v=20261012-attendance-reports">

<section class="attendance-report-page" data-report-page>
    <header class="report-intro">
        <div>
            <p class="eyebrow">تقارير المشرف</p>
            <h2>الحضور والغياب حسب الفترة والنطاق</h2>
            <p>اختر يومًا أو أسبوعًا أو شهرًا، ثم حدّد طالبًا أو فصلًا أو كل الفصول التي تشرف عليها.</p>
        </div>
        <div class="report-period-badge"><span>الفترة الحالية</span><strong>{{ $dateFrom->format('Y-m-d') }} — {{ $dateTo->format('Y-m-d') }}</strong></div>
    </header>

    <form class="report-filters" method="get" aria-label="خيارات تقرير الحضور">
        <label><span>نوع التقرير</span><select name="period" data-report-period>
            <option value="daily" @selected($period === 'daily')>يومي</option>
            <option value="weekly" @selected($period === 'weekly')>أسبوعي</option>
            <option value="monthly" @selected($period === 'monthly')>شهري</option>
            <option value="custom" @selected($period === 'custom')>فترة مخصصة</option>
        </select></label>
        <label data-report-date><span>اليوم</span><input type="date" name="date" value="{{ request('date', $dateFrom->format('Y-m-d')) }}"></label>
        <label data-report-month hidden><span>الشهر</span><input type="month" name="month" value="{{ $month }}"></label>
        <label data-report-from hidden><span>من</span><input type="date" name="date_from" value="{{ request('date_from', $dateFrom->format('Y-m-d')) }}"></label>
        <label data-report-to hidden><span>إلى</span><input type="date" name="date_to" value="{{ request('date_to', $dateTo->format('Y-m-d')) }}"></label>
        <label><span>النطاق</span><select name="classroom_id" data-report-classroom>
            <option value="">كل الفصول المشرفة</option>
            @foreach($classes as $classroom)
                <option value="{{ $classroom->id }}" @selected($selectedClassroomId === $classroom->id)>{{ $classroom->name }}{{ $classroom->section ? ' — '.$classroom->section : '' }}</option>
            @endforeach
        </select></label>
        <label><span>الطالب</span><select name="student_id" data-report-student>
            <option value="">كل الطلاب</option>
            @foreach($availableStudents as $student)
                <option value="{{ $student->id }}" data-classroom-id="{{ $student->classroom_id }}" @selected($selectedStudentId === $student->id)>{{ $student->user?->name }} — {{ $student->classroom?->name }} {{ $student->classroom?->section }}</option>
            @endforeach
        </select></label>
        <button class="report-button report-button--primary" type="submit">عرض التقرير</button>
    </form>

    <p class="report-note">يُحسب التقرير من سجلات الحضور المحفوظة خلال الفترة المحددة. الحضور هو كل سجل ليس غيابًا.</p>

    <section class="report-stats" aria-label="ملخص التقرير">
        <article><span>السجلات</span><strong>{{ $summary['total'] }}</strong></article>
        <article class="is-present"><span>حاضر</span><strong>{{ $summary['present'] }}</strong></article>
        <article class="is-absent"><span>غائب</span><strong>{{ $summary['absent'] }}</strong></article>
        <article class="is-rate"><span>نسبة الحضور</span><strong>{{ number_format($summary['rate'], 1) }}%</strong></article>
    </section>

    <section class="report-panel">
        <div class="report-panel-head"><div><p class="eyebrow">ملخص الطلاب</p><h2>الحضور لكل طالب</h2></div><span>{{ $studentSummaries->count() }} طالبًا ضمن النطاق</span></div>
        <div class="report-table-wrap">
            <table class="report-table">
                <thead><tr><th>الطالب</th><th>الفصل</th><th>السجلات</th><th>حاضر</th><th>غائب</th><th>نسبة الحضور</th></tr></thead>
                <tbody>
                @forelse($studentSummaries as $item)
                    <tr><td><a href="{{ route('supervisor.students.show', $item['student']) }}">{{ $item['student']->user?->name ?? 'بدون اسم' }}</a><small>{{ $item['student']->student_number }}</small></td><td>{{ $item['student']->classroom?->name }} {{ $item['student']->classroom?->section }}</td><td>{{ $item['total'] }}</td><td class="present-number">{{ $item['present'] }}</td><td class="absent-number">{{ $item['absent'] }}</td><td><strong>{{ number_format($item['rate'], 1) }}%</strong></td></tr>
                @empty
                    <tr><td colspan="6" class="report-empty">لا توجد سجلات مطابقة لهذه الخيارات.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="report-panel">
        <div class="report-panel-head"><div><p class="eyebrow">التوزيع الزمني</p><h2>ملخص كل يوم</h2></div><span>{{ $dailySummaries->count() }} يومًا مسجلًا</span></div>
        <div class="report-table-wrap">
            <table class="report-table report-table--compact">
                <thead><tr><th>التاريخ</th><th>السجلات</th><th>حاضر</th><th>غائب</th></tr></thead>
                <tbody>
                @forelse($dailySummaries as $day)
                    <tr><td>{{ $day['date'] }}</td><td>{{ $day['total'] }}</td><td class="present-number">{{ $day['present'] }}</td><td class="absent-number">{{ $day['absent'] }}</td></tr>
                @empty
                    <tr><td colspan="4" class="report-empty">لا توجد أيام مسجلة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="report-panel">
        <div class="report-panel-head"><div><p class="eyebrow">التفاصيل</p><h2>سجل الحضور المحدد</h2></div><span>{{ $records->count() }} سجلًا</span></div>
        <div class="report-table-wrap">
            <table class="report-table report-table--compact">
                <thead><tr><th>التاريخ</th><th>الطالب</th><th>الفصل</th><th>الحالة</th><th>الملاحظة</th></tr></thead>
                <tbody>
                @forelse($records as $record)
                    @php $absent = in_array($record->status->value, ['absent', 'excused_absence'], true); @endphp
                    <tr><td>{{ $record->date->format('Y-m-d') }}</td><td>{{ $record->student->user?->name }}</td><td>{{ $record->student->classroom?->name }} {{ $record->student->classroom?->section }}</td><td class="{{ $absent ? 'absent-number' : 'present-number' }}">{{ $absent ? 'غائب' : 'حاضر' }}</td><td>{{ $record->notes ?: '—' }}</td></tr>
                @empty
                    <tr><td colspan="5" class="report-empty">لا توجد سجلات مطابقة لهذه الخيارات.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</section>
@push('scripts')
<script src="{{ asset('js/supervisor-report.js') }}?v=20261012-attendance-reports" defer></script>
@endpush
@endsection
