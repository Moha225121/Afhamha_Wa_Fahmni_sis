@extends('parent.layout')

@section('title', 'النتائج')

@section('content')
    <section class="page-title">
        <p>نتائج الاختبارات المنشورة</p>
        <h1>النتائج</h1>
    </section>

    @if($children->isEmpty())
        <section class="empty-state">
            <h2>لا يوجد أبناء مرتبطون</h2>
            <p>لا يمكن عرض نتائج بدون ربط طالب بحساب ولي الأمر.</p>
        </section>
    @else
        @include('parent.partials.child-switcher')
        @include('parent.partials.subject-filter')

        <section class="metrics-grid">
            <article class="metric wide">
                <span>{{ $selectedStudent->user->name }} · {{ $selectedSubjectId ? 'متوسط درجات المادة' : 'متوسط درجات جميع المواد' }}</span>
                @if($summary['average_percent'] === null)<strong class="metric-empty">لا توجد نتائج بعد</strong>@else<strong>{{ $summary['average_percent'] }}%</strong>@endif
                <small class="metric-help">متوسط نسب الدرجات المنشورة{{ $selectedSubjectId ? ' للمادة المختارة' : ' في جميع المواد' }}</small>
            </article>
            <article class="metric">
                <span>عدد النتائج المنشورة</span>
                <strong>{{ $summary['published_grades'] }}</strong>
            </article>
        </section>

        @include('parent.partials.published-results')

        @if($recentGrades->isNotEmpty() || $publications->isEmpty())
        <section class="list-section">
            <div class="section-title"><h2>نتائج الاختبارات</h2></div>
            @forelse($recentGrades as $grade)
                @php($score = rtrim(rtrim(number_format((float) $grade->score, 2, '.', ''), '0'), '.'))
                @php($total = rtrim(rtrim(number_format((float) $grade->total_score, 2, '.', ''), '0'), '.'))
                <div class="list-row academic-record">
                    <div>
                        <div class="academic-item-heading"><strong>{{ $grade->title }}</strong><span class="academic-subject">المادة: {{ $grade->subject_label }}</span></div>
                        <span class="academic-date">تاريخ النشر: {{ $grade->published_at ? \Illuminate\Support\Carbon::parse($grade->published_at)->format('Y-m-d') : '-' }}</span>
                    </div>
                    <b class="academic-score">{{ $score }} / {{ $total }}</b>
                </div>
            @empty
                <p class="muted-line academic-empty">{{ $selectedSubjectId ? 'لا توجد نتائج منشورة لهذه المادة.' : 'لا توجد نتائج منشورة لهذا الطالب.' }}</p>
            @endforelse
        </section>
        @endif
    @endif
@endsection
