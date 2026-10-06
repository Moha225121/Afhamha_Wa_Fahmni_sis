<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>طباعة بطاقات الطلاب</title>
    <style>
        @page { size: A4; margin: 0; }
        body { margin: 0; background: #eef2f7; }
        .toolbar { display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; background: #fff; color: #132d57; font: 14px Tahoma, Arial, sans-serif; }
        .toolbar button { padding: 9px 16px; border: 0; border-radius: 8px; background: #123b79; color: #fff; cursor: pointer; font: inherit; }
        .page { position: relative; width: 210mm; height: 297mm; margin: 12px auto; background: #fff; overflow: hidden; }
        .card-position { position: absolute; width: 94mm; height: 62mm; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .page { margin: 0; break-after: page; page-break-after: always; }
            .page:last-child { break-after: auto; page-break-after: auto; }
        }
    </style>
</head>
<body>
    <div class="toolbar"><span>بطاقات الطلاب: {{ $students->count() }}</span><button type="button" onclick="window.print()">طباعة / حفظ PDF</button></div>
    @foreach($students->chunk(8) as $page)
        <div class="page">
            @foreach($page->values() as $index => $student)
                <div class="card-position" style="left:{{ 8 + (($index % 2) * 101) }}mm;top:{{ 8 + (intdiv($index, 2) * 68) }}mm">
                    @include('admin.accounts.card-fragment', ['student' => $student, 'pdfLogo' => asset('icons/nasr-logo.jpg')])
                </div>
            @endforeach
        </div>
    @endforeach
</body>
</html>
