@php($cardTheme = preg_match('/^#[0-9a-f]{6}$/i', $schoolBranding['theme_color'] ?? '') ? $schoolBranding['theme_color'] : '#008C95')
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>طباعة بطاقات الطلاب</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; min-height: 100%; font-family: Tahoma, Arial, sans-serif; color: {{ $cardTheme }}; }
        body { background: #f1f2f5; }
        .toolbar { position: sticky; top: 0; z-index: 2; display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 20px; background: #fff; color: {{ $cardTheme }}; font-size: 14px; }
        .toolbar-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .toolbar button { padding: 9px 16px; border: 0; border-radius: 8px; background: {{ $cardTheme }}; color: #fff; cursor: pointer; font: inherit; }
        .toolbar button:disabled { opacity: .5; cursor: not-allowed; }
        .preview-pages { padding: 1px 0; }
        .page { position: relative; width: 210mm; height: 297mm; margin: 16px auto; overflow: hidden; background: #fff; box-shadow: 0 2px 14px {{ $cardTheme }}22; }
        .card-position { position: absolute; width: 84mm; height: 53mm; }
        .card-selection { position: absolute; top: -4.2mm; right: 0; padding: .5mm 1mm; border: 1px solid #d5dce7; border-radius: 1mm; background: #fff; font-size: 9px; line-height: 1; white-space: nowrap; cursor: pointer; }
        .card-selection input { margin: 0 0 0 2px; vertical-align: middle; }
        #print-pages { display: none; }
        .print-page-grid { display: grid; grid-template-columns: 84mm 84mm; grid-template-rows: repeat(5, 53mm); column-gap: 18mm; row-gap: 4mm; width: 186mm; height: 281mm; margin: 8mm auto 0; direction: ltr; }
        .print-page-grid .card-position { position: relative; inset: auto; width: 84mm; height: 53mm; }
        @media print {
            html, body { width: 210mm; min-height: 0; background: #fff; }
            .toolbar, .preview-pages { display: none !important; }
            #print-pages { display: block; }
            .page { width: 210mm; height: 297mm; margin: 0; overflow: hidden; box-shadow: none; break-after: page; page-break-after: always; }
            .page:last-child { break-after: auto; page-break-after: auto; }
            .card-selection { display: none !important; }
            .student-id-card { width: 84mm !important; height: 53mm !important; min-width: 84mm !important; min-height: 53mm !important; max-width: 84mm !important; max-height: 53mm !important; box-sizing: border-box !important; }
        }
        @media screen and (max-width: 850px) {
            .toolbar { align-items: flex-start; flex-direction: column; }
            .page { transform-origin: top center; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <span id="selection-count" aria-live="polite">تم تحديد {{ $students->count() }} من {{ $students->count() }} بطاقة</span>
        <div class="toolbar-actions">
            <button type="button" id="select-all">تحديد الكل</button>
            <button type="button" id="select-none">إلغاء التحديد</button>
            <button type="button" id="print-selected">طباعة المحدد</button>
            <button type="button" id="download-pdf-selected">تنزيل PDF للمحدد</button>
        </div>
    </div>

    <div class="preview-pages">
        @foreach($students->chunk(10) as $page)
            <div class="page preview-page">
                @foreach($page->values() as $index => $student)
                    <div class="card-position" data-student-id="{{ $student->id }}" style="left:{{ 12 + (($index % 2) * 102) }}mm;top:{{ 8 + (intdiv($index, 2) * 57) }}mm">
                        <label class="card-selection"><input type="checkbox" class="card-checkbox" checked> طباعة البطاقة</label>
                        @include('admin.accounts.card-fragment', ['student' => $student, 'pdfLogo' => \App\Support\SchoolBranding::logoUrl()])
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    <div id="print-pages" aria-hidden="true"></div>
    <form id="pdf-download-form" method="get" action="{{ route('admin.students.cards') }}" hidden>
        @if(request()->filled('classroom_id'))<input type="hidden" name="classroom_id" value="{{ request('classroom_id') }}">@endif
        @if(request()->filled('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
        <input type="hidden" name="format" value="pdf">
    </form>
    <script src="{{ asset('js/student-card-pagination.js') }}?v=1" defer></script>
    <script src="{{ asset('js/admin-card-selection.js') }}?v=2" defer></script>
</body>
</html>
