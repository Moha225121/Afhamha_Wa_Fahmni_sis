@foreach($publications as $publication)
    <section class="list-section">
        <div class="section-title"><h2>{{ $publication->period }}</h2></div>
        <p class="academic-result-note">{{ $publication->academic_year }} · {{ $publication->term }}</p>
        @foreach($publication->result['subjects'] as $subject)
            <div class="list-row academic-record">
                <div>
                    <span class="academic-subject">المادة: {{ $subject['subject_label'] }}</span>
                    @if($subject['notes'] ?? null)<span class="academic-date">{{ $subject['notes'] }}</span>@endif
                </div>
                <b class="academic-score">{{ $subject['score'] }} / {{ $subject['maximum_score'] }}</b>
            </div>
        @endforeach
        @if(!$selectedSubjectId)
            <div class="metrics-grid">
                <article class="metric"><span>المجموع العام</span><strong>{{ $publication->result['total'] }} / {{ $publication->result['maximum'] }}</strong></article>
                <article class="metric"><span>النسبة والتقدير العام</span><strong>{{ $publication->result['percentage'] }}% — {{ $publication->result['rating'] }}</strong></article>
                <article class="metric"><span>النتيجة العامة</span><strong>{{ $publication->result['passed'] ? 'ناجح' : 'راسب' }}</strong></article>
            </div>
        @endif
    </section>
@endforeach
