<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: dejavusans; direction: rtl; color: #182b32; }
        h1 { color: #174f4c; }
        table { width: 100%; border-collapse: collapse; margin: 14px 0 24px; }
        th, td { border: 1px solid #ccd8d8; padding: 8px; text-align: right; }
        th { background: #edf5f3; }
        .summary { width: 48%; display: inline-block; vertical-align: top; }
        .muted { color: #60777b; }
    </style>
</head>
<body>
    <h1>مركز التقارير المدرسية</h1>
    <p class="muted">أُنشئ في {{ $generatedAt->format('Y-m-d H:i') }}</p>
    <p>الفترة: {{ $filters['from'] ?? 'البداية' }} إلى {{ $filters['to'] ?? 'اليوم' }} · الصف: {{ isset($filters['classroom_id']) ? ($classrooms->firstWhere('id', (int) $filters['classroom_id'])?->name ?? '—') : 'كل الصفوف' }} · حالة الاختبار: {{ ['scheduled' => 'مجدول', 'published' => 'منشور', 'completed' => 'مكتمل', 'cancelled' => 'ملغي'][$examStatus] ?? 'مجدول' }}</p>
    <section class="summary"><h2>الطلاب حسب الحالة</h2><table><thead><tr><th>الحالة</th><th>العدد</th></tr></thead><tbody>@forelse($students as $row)<tr><td>{{ $row->status }}</td><td>{{ $row->total }}</td></tr>@empty<tr><td colspan="2">لا توجد سجلات</td></tr>@endforelse</tbody></table></section>
    <section class="summary"><h2>المعلمون حسب الحالة</h2><table><thead><tr><th>الحالة</th><th>العدد</th></tr></thead><tbody>@forelse($teachers as $row)<tr><td>{{ $row->status }}</td><td>{{ $row->total }}</td></tr>@empty<tr><td colspan="2">لا توجد سجلات</td></tr>@endforelse</tbody></table></section>
    <h2>الحضور المسجل</h2><table><thead><tr><th>الحالة</th><th>العدد</th></tr></thead><tbody>@forelse($attendance as $row)<tr><td>{{ $row->status }}</td><td>{{ $row->total }}</td></tr>@empty<tr><td colspan="2">لا توجد سجلات ضمن المدة</td></tr>@endforelse</tbody></table>
    <h2>النتائج المنشورة</h2><table><tbody><tr><th>عدد الدرجات المنشورة</th><td>{{ $results->total }}</td></tr><tr><th>متوسط النسب</th><td>{{ $results->average === null ? '—' : number_format($results->average, 2).'%' }}</td></tr><tr><th>أعلى نسبة</th><td>{{ $results->highest === null ? '—' : number_format($results->highest, 2).'%' }}</td></tr><tr><th>أدنى نسبة</th><td>{{ $results->lowest === null ? '—' : number_format($results->lowest, 2).'%' }}</td></tr></tbody></table>
    <h2>الاختبارات ضمن النطاق، بجميع الحالات</h2><table><thead><tr><th>الاختبار</th><th>المادة</th><th>الصف</th><th>الموعد</th><th>الحالة</th><th>الأسئلة</th></tr></thead><tbody>@forelse($exams as $exam)<tr><td>{{ $exam->title }}</td><td>{{ $exam->subject }}</td><td>{{ $exam->classroom }} {{ $exam->section }}</td><td>{{ \Illuminate\Support\Carbon::parse($exam->starts_at)->format('Y-m-d H:i') }}</td><td>{{ ['draft'=>'مسودة','scheduled'=>'مجدول','published'=>'منشور','completed'=>'مكتمل','cancelled'=>'ملغي'][$exam->status] ?? $exam->status }}</td><td>{{ $exam->questions_count }}</td></tr>@empty<tr><td colspan="6">لا توجد اختبارات ضمن الفلاتر الحالية</td></tr>@endforelse</tbody></table>
</body>
</html>
