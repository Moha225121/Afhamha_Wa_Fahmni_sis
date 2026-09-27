@foreach($publications as $publication)
<section class="list-section"><div class="section-title"><h2>{{ $publication->period }}</h2></div><p>{{ $publication->academic_year }} — {{ $publication->term }}</p>
@foreach($publication->result['subjects'] as $subject)<div class="list-row"><div><strong>{{ $subject['subject'] }}</strong>@if($subject['notes'])<span>{{ $subject['notes'] }}</span>@endif</div><b>{{ $subject['score'] }} / {{ $subject['maximum_score'] }}</b></div>@endforeach
<div class="metrics-grid"><article class="metric"><span>المجموع</span><strong>{{ $publication->result['total'] }} / {{ $publication->result['maximum'] }}</strong></article><article class="metric"><span>النسبة والتقدير</span><strong>{{ $publication->result['percentage'] }}% — {{ $publication->result['rating'] }}</strong></article><article class="metric"><span>النتيجة</span><strong>{{ $publication->result['passed']?'ناجح':'راسب' }}</strong></article></div></section>
@endforeach
