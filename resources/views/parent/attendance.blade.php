@extends('parent.layout')

@section('title', 'الحضور')

@section('content')
    <section class="page-title">
        <p>متابعة بدون تعديل</p>
        <h1>الحضور</h1>
    </section>

    @if($children->isEmpty())
        <section class="empty-state"><h2>لا يوجد أبناء مرتبطون</h2><p>لا يمكن عرض الحضور قبل ربط طالب بحساب ولي الأمر.</p></section>
    @else
        @include('parent.partials.child-switcher')
        <form class="filters"><select name="period"><option value="week">هذا الأسبوع</option><option value="month">هذا الشهر</option><option value="semester">الفصل الدراسي</option><option value="custom">تاريخ مخصص</option></select><input type="date" name="from"><input type="date" name="to"><input type="hidden" name="student" value="{{ $selectedStudent->id }}"><button>تطبيق</button></form>
        <section class="metrics-grid">
            <article class="metric"><span>نسبة الحضور</span><strong>{{ $summary['attendance_percent'] === null ? '-' : $summary['attendance_percent'].'%' }}</strong></article>
            <article class="metric"><span>إجمالي السجلات</span><strong>{{ $summary['attendance_total'] }}</strong></article>
            <article class="metric"><span>غياب</span><strong>{{ $summary['absent'] }}</strong></article>
            <article class="metric"><span>تأخر / بعذر</span><strong>{{ $summary['late'] }} / {{ $summary['excused'] }}</strong></article>
        </section>
        <section class="list-section">
            <div class="section-title"><h2>سجل {{ $selectedStudent->user->name }}</h2></div>
            @forelse($records as $record)
                @php($labels = ['present' => 'حاضر', 'absent' => 'غائب', 'late' => 'متأخر', 'excused_absence' => 'غياب بعذر', 'excused_late' => 'تأخير بعذر'])
                <div class="list-row">
                    <div><strong>{{ $record->date }}</strong><span>{{ $record->arrival_time ? 'وقت الوصول: '.$record->arrival_time : '' }} {{ $record->late_minutes ? ' — '.$record->late_minutes.' دقيقة' : '' }}</span><span>{{ $record->excuse_reason ?: $record->notes }}</span></div>
                    <b>{{ $labels[$record->status] ?? $record->status }}</b>
                </div>
            @empty
                <p class="muted-line">لا توجد سجلات حضور لهذا الطالب.</p>
            @endforelse
        </section>
    @endif
@endsection
