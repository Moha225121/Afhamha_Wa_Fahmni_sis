<!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $builder['report']['title'] }} | طباعة التقرير</title><link rel="stylesheet" href="{{ asset('css/teacher-reports.css') }}?v=20261028-4"></head>
<body class="trp-print-document">
@php
    $periodLabels = ['weekly' => 'أسبوعي', 'monthly' => 'شهري', 'midterm' => 'نصفي', 'final' => 'نهائي', 'overall' => 'شامل'];
    $ratingLabels = ['attention' => 'يرجى الاهتمام', 'good' => 'جيد', 'very_good' => 'جيد جدًا', 'excellent' => 'ممتاز'];
    $behaviourLabels = ['calm' => 'هادئ', 'disruptive' => 'مشاغب'];
    $formatNumber = fn ($value) => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $printStudents = collect($builder['entries'])->map(fn ($entry) => ['id' => $entry['student_id'], 'name' => $entry['student_name']])->unique('id')->values()->when($printStudent ?? null, fn ($students) => $students->where('id', $printStudent));
@endphp
<main class="trp-page">
    <div class="trp-print-toolbar"><p>اطبع التقرير، أو اختر «حفظ بصيغة PDF» من نافذة الطباعة.</p><div class="trp-saved-actions"><a class="trp-button trp-button-soft" href="{{ route('teacher.reports.show', $builder['report']['id']) }}">العودة إلى التقرير</a><button class="trp-button trp-button-primary" type="button" onclick="window.print()">طباعة / حفظ PDF</button></div></div>
    @if($printFormat === 'class')
        <article class="trp-paper trp-print-class"><header class="trp-paper-header"><h1 class="trp-paper-school">{{ $schoolBranding['school_name'] ?? 'المدرسة' }}</h1><p class="trp-paper-title">{{ $builder['report']['title'] }}</p><p class="trp-paper-period">{{ $builder['classroom']['label'] }} · {{ $periodLabels[$builder['config']['report_type']] }}@if($builder['config']['from'] || $builder['config']['to']) · {{ $builder['config']['from'] ?: 'البداية' }} — {{ $builder['config']['to'] ?: 'النهاية' }}@endif</p></header><div class="trp-paper-table-wrap"><table class="trp-paper-table"><thead><tr><th scope="col">الطالب</th><th scope="col">المادة</th><th scope="col">الدرجة %</th><th scope="col">التقدير</th><th scope="col">السلوك</th><th scope="col">النشاط</th><th scope="col">ملاحظات</th></tr></thead><tbody>@foreach($builder['entries'] as $entry)<tr><th scope="row">{{ $entry['student_name'] }}</th><td>{{ $entry['subject_name'] }}</td><td>{{ $formatNumber($entry['score']) }}</td><td>{{ $ratingLabels[$entry['rating']] ?? '—' }}</td><td>{{ $behaviourLabels[$entry['behaviour']] ?? '—' }}</td><td class="trp-print-text">{{ $entry['activity'] ?: '—' }}</td><td class="trp-print-text">{{ $entry['notes'] ?: '—' }}</td></tr>@endforeach</tbody></table></div><footer class="trp-paper-footer"><span>المعلم: {{ auth()->user()->name }}</span><span>التوقيع: ................................</span></footer></article>
    @else
        @foreach($printStudents as $student)
            @include('teacher.reports._card')
        @endforeach
    @endif
</main>
</body></html>
