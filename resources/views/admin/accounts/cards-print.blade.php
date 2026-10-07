<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>طباعة بطاقات الطلاب</title>
    <style>
        @page { size: A4; margin: 0; }
        body { margin: 0; background: #F1F2F5; }
        .toolbar { position: sticky; top: 0; z-index: 2; display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 20px; background: #fff; color: #101632; font: 14px Tahoma, Arial, sans-serif; }
        .toolbar-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .toolbar button { padding: 9px 16px; border: 0; border-radius: 8px; background: #101632; color: #fff; cursor: pointer; font: inherit; }
        .toolbar button:disabled { opacity: .5; cursor: not-allowed; }
        .page { position: relative; width: 210mm; height: 297mm; margin: 12px auto; background: #fff; overflow: hidden; }
        .card-position { position: absolute; width: 94mm; height: 62mm; }
        .card-selection { position: absolute; top: -6mm; right: 0; padding: 1mm 2mm; background: #fff; border-radius: 2mm; font: 12px Tahoma, Arial, sans-serif; cursor: pointer; }
        .print-pages { display: none; }
        @media print {
            body { background: #fff; }
            .toolbar, .preview-pages { display: none; }
            .print-pages { display: block; }
            .page { margin: 0; break-after: page; page-break-after: always; }
            .page:last-child { break-after: auto; page-break-after: auto; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <span id="selection-count" aria-live="polite">تم تحديد {{ $students->count() }} من {{ $students->count() }} بطاقة</span>
        <div class="toolbar-actions">
            <button type="button" id="select-all">تحديد الكل</button>
            <button type="button" id="select-none">إلغاء التحديد</button>
            <button type="button" id="print-selected">طباعة المحدد / حفظ PDF</button>
        </div>
    </div>
    <div class="preview-pages">
    @foreach($students->chunk(8) as $page)
        <div class="page">
            @foreach($page->values() as $index => $student)
                <div class="card-position" style="left:{{ 8 + (($index % 2) * 101) }}mm;top:{{ 8 + (intdiv($index, 2) * 68) }}mm">
                    <label class="card-selection"><input type="checkbox" class="card-checkbox" checked> طباعة هذه البطاقة</label>
                    @include('admin.accounts.card-fragment', ['student' => $student, 'pdfLogo' => asset(\App\Support\SchoolBranding::LOGO)])
                </div>
            @endforeach
        </div>
    @endforeach
    </div>
    <div class="print-pages" id="print-pages" aria-hidden="true"></div>
    <script src="{{ asset('js/admin-card-selection.js') }}?v=1" defer></script>
</body>
</html>
