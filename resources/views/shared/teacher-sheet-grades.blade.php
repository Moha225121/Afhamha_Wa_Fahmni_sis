@if($teacherSheetGrades->isNotEmpty())
    <section class="list-section">
        <div class="section-title"><h2>درجات الاختبارات المرصودة</h2></div>
        @foreach($teacherSheetGrades as $grade)
            @php($score = rtrim(rtrim(number_format((float) $grade->score, 2, '.', ''), '0'), '.'))
            @php($total = rtrim(rtrim(number_format((float) $grade->total_score, 2, '.', ''), '0'), '.'))
            <div class="list-row">
                <div>
                    <strong>{{ $grade->title }}</strong>
                    <span>{{ $grade->subject }} · {{ $grade->teacher }} · {{ $grade->published_at ? \Illuminate\Support\Carbon::parse($grade->published_at)->format('Y-m-d') : '-' }}</span>
                </div>
                <b class="numeric-value">{{ $score }} من {{ $total }}</b>
            </div>
        @endforeach
    </section>
@endif
