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

        <section class="metrics-grid">
            <article class="metric wide">
                <span>{{ $selectedStudent->user->name }}</span>
                <strong>{{ $summary['average_percent'] === null ? '-' : $summary['average_percent'].'%' }}</strong>
            </article>
            <article class="metric">
                <span>منشورة</span>
                <strong>{{ $summary['published_grades'] }}</strong>
            </article>
        </section>

        <section class="list-section">
            @forelse($recentGrades as $grade)
                @php($score = rtrim(rtrim(number_format((float) $grade->score, 2, '.', ''), '0'), '.'))
                @php($total = rtrim(rtrim(number_format((float) $grade->total_score, 2, '.', ''), '0'), '.'))
                <div class="list-row">
                    <div>
                        <strong>{{ $grade->subject }}</strong>
                        <span>{{ $grade->title }} · {{ $grade->published_at ? \Illuminate\Support\Carbon::parse($grade->published_at)->format('Y-m-d') : '-' }}</span>
                    </div>
                    <b>{{ $score }} / {{ $total }}</b>
                </div>
            @empty
                <p class="muted-line">لا توجد نتائج منشورة لهذا الطالب.</p>
            @endforelse
        </section>
    @endif
@endsection
