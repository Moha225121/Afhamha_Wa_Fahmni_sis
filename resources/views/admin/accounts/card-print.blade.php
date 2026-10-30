@php($cardTheme = preg_match('/^#[0-9a-f]{6}$/i', $schoolBranding['theme_color'] ?? '') ? $schoolBranding['theme_color'] : '#008C95')
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>بطاقة الطالب</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { width: 210mm; min-height: 297mm; margin: 0; font-family: Tahoma, Arial, sans-serif; }
        body { background: #f1f2f5; }
        .toolbar { display: flex; justify-content: center; gap: 8px; padding: 12px; }
        .toolbar button, .toolbar a { padding: 9px 14px; border: 0; border-radius: 8px; background: {{ $cardTheme }}; color: #fff; text-decoration: none; font: inherit; }
        .page { position: relative; width: 210mm; height: 297mm; margin: 0 auto 16px; overflow: hidden; background: #fff; box-shadow: 0 2px 14px {{ $cardTheme }}22; }
        .card-position { position: absolute; top: 8mm; left: 12mm; width: 84mm; height: 53mm; }
        @media print {
            html, body { width: 210mm; height: 297mm; min-height: 0; background: #fff; }
            .toolbar { display: none !important; }
            .page { width: 210mm; height: 297mm; margin: 0; box-shadow: none; break-after: auto; page-break-after: auto; }
            .student-id-card { width: 84mm !important; height: 53mm !important; min-width: 84mm !important; min-height: 53mm !important; max-width: 84mm !important; max-height: 53mm !important; box-sizing: border-box !important; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">طباعة</button>
        <a href="{{ route('admin.students.account-card', ['student' => $student, 'format' => 'pdf']) }}">تنزيل PDF</a>
    </div>
    <main class="page">
        <div class="card-position">
            @include('admin.accounts.card-fragment', ['student' => $student, 'pdfLogo' => \App\Support\SchoolBranding::logoUrl()])
        </div>
    </main>
</body>
</html>
