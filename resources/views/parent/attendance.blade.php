@extends('parent.layout')

@section('title', 'الحضور')

@section('content')
    <section class="page-title">
        <p>متابعة حضور أبنائك</p>
        <h1>الحضور</h1>
        <p>تظهر هنا سجلات الحضور التي حفظتها المدرسة، مع الملاحظات وأيام الغياب.</p>
    </section>

    @if($children->isEmpty())
        <section class="empty-state"><h2>لا يوجد أبناء مرتبطون</h2><p>لا يمكن عرض الحضور قبل ربط طالب بحساب ولي الأمر.</p></section>
    @else
        @include('parent.partials.child-switcher')
        <form class="filters" data-parent-attendance-filters aria-label="تصفية سجل الحضور">
            <label>الفترة<select name="period" data-attendance-period>
                @foreach(['all' => 'كل السجلات', 'week' => 'هذا الأسبوع', 'month' => 'هذا الشهر', 'semester' => 'آخر أربعة أشهر', 'custom' => 'تاريخ مخصص'] as $value => $label)
                    <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
                @endforeach
            </select></label>
            <label data-attendance-range @if($period !== 'custom') hidden @endif>من<input type="date" name="from" value="{{ $dateFrom }}" @disabled($period !== 'custom')></label>
            <label data-attendance-range @if($period !== 'custom') hidden @endif>إلى<input type="date" name="to" value="{{ $dateTo }}" @disabled($period !== 'custom')></label>
            <input type="hidden" name="student" value="{{ $selectedStudent->id }}"><button>تطبيق</button>
        </form>
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
                    <div><strong>{{ \Illuminate\Support\Carbon::parse($record->date)->toDateString() }}</strong><span>{{ $record->arrival_time ? 'وقت الوصول: '.$record->arrival_time : '' }} {{ $record->late_minutes ? ' — '.$record->late_minutes.' دقيقة' : '' }}</span>@if($record->excuse_reason)<span>{{ $record->excuse_reason }}</span>@endif @if($record->notes)<span>{{ $record->notes }}</span>@endif</div>
                    <b>{{ $labels[$record->status] ?? $record->status }}</b>
                </div>
            @empty
                <p class="muted-line">لا توجد سجلات حضور لهذا الطالب في الفترة المختارة.</p>
            @endforelse
        </section>
        {{ $records->links() }}
    @endif
@endsection

@push('scripts')
<script>
(() => {
    const form = document.querySelector('[data-parent-attendance-filters]');
    if (!form) return;
    const period = form.querySelector('[data-attendance-period]');
    const updateRange = () => form.querySelectorAll('[data-attendance-range]').forEach(label => {
        label.hidden = period.value !== 'custom';
        label.querySelector('input').disabled = period.value !== 'custom';
    });
    period.addEventListener('change', updateRange);
    updateRange();
})();
</script>
@endpush
