<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $builder['report']['title'] }} | تقرير المعلم</title><link rel="stylesheet" href="{{ asset('css/teacher-reports.css') }}?v=20261028-2"></head>
<body class="trp-print-document">
@php
    $periodLabels = ['weekly' => 'أسبوعي', 'monthly' => 'شهري', 'midterm' => 'نصفي', 'final' => 'نهائي', 'overall' => 'شامل'];
    $ratingLabels = ['attention' => 'يرجى الاهتمام', 'good' => 'جيد', 'very_good' => 'جيد جدًا', 'excellent' => 'ممتاز'];
    $reportTeacherName = $builder['teacher_name'];
    $reportStudents = collect($builder['entries'])->map(fn ($entry) => ['id' => $entry['student_id'], 'name' => $entry['student_name']])->unique('id')->values();
@endphp
<main class="trp-page">
    <div class="trp-print-toolbar"><p>تقرير من {{ $reportTeacherName }}</p><div class="trp-saved-actions"><a class="trp-button trp-button-soft" href="{{ route('received-reports.index', $selectedStudent ? ['student' => $selectedStudent->id] : []) }}">التقارير الواردة</a><button type="button" class="trp-button trp-button-primary" onclick="window.print()">طباعة / حفظ PDF</button></div></div>
    @foreach($reportStudents as $student)
        @include('teacher.reports._card')
    @endforeach
</main>
</body>
</html>
