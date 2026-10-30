<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $name }} | معاينة المرفق</title>
    <link rel="stylesheet" href="{{ asset('css/library.css') }}?v=20260930-reader2">
    <style>
        :root{--school-navy:#15233d;--school-navy-light:#263e60;--school-gray:#edf2f7;--school-white:#fff;--school-gold:#c6a443;color-scheme:light}
        body{margin:0;padding:10px;background:#f5f8fb;font:13px Cairo,Arial,sans-serif;color:var(--school-navy)}
        .ap-attachment-reader{max-width:1500px;margin:auto;min-width:0}
        .ap-attachment-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:0 0 10px;padding:2px 3px}
        .ap-attachment-name{min-width:0;margin:0;font-size:13px;line-height:1.7;font-weight:700;overflow-wrap:anywhere}
        .ap-attachment-actions{display:flex;align-items:center;gap:7px;flex:none}
        .ap-attachment-reader .lib-button{font-size:11px;min-height:34px;padding:6px 11px}
        .ap-attachment-reader .lib-reader-canvas{height:calc(100dvh - 83px);min-height:260px;border-radius:10px}
        .ap-attachment-reader .lib-pdf-controls{gap:10px;padding:9px;background:#f4f7fb}
        .ap-attachment-reader .lib-pdf-page-status{margin:0;padding:6px 10px;background:#f4f7fb}
        .ap-attachment-reader .lib-pdf-jump input,.ap-attachment-reader .lib-pdf-zoom select{font:inherit;font-size:11px}
        @media(max-width:600px){body{padding:7px}.ap-attachment-toolbar{align-items:flex-start}.ap-attachment-name{font-size:11px}.ap-attachment-reader .lib-pdf-controls{gap:8px}.ap-attachment-actions .lib-button{padding:5px 8px;font-size:10px}.ap-attachment-reader .lib-reader-canvas{height:calc(100dvh - 86px)}.ap-attachment-reader .lib-pdf-pages{gap:5px}.ap-attachment-reader .lib-pdf-controls .lib-button{font-size:10px;padding:5px 7px}}
    </style>
</head>
<body>
<main class="library-reader ap-attachment-reader">
    <header class="ap-attachment-toolbar">
        <h1 class="ap-attachment-name">{{ $name }}</h1>
        <div class="ap-attachment-actions">
            <button type="button" class="lib-button lib-button--secondary" data-library-fullscreen hidden>ملء الشاشة</button>
            <a class="lib-button lib-button--primary" href="{{ $fileUrl }}?download=1">تنزيل الملف</a>
        </div>
    </header>
    <div class="lib-reader-canvas" data-library-reader-canvas data-pdf-url="{{ $fileUrl }}" data-pdf-assets="{{ asset('vendor/pdfjs') }}/" aria-label="قارئ {{ $name }}" tabindex="0">
        <div class="lib-pdf-controls" aria-label="أدوات قراءة المرفق">
            <div class="lib-pdf-pages">
                <button type="button" class="lib-button lib-button--secondary" data-pdf-previous disabled>السابق</button>
                <form data-pdf-jump class="lib-pdf-jump">
                    <label for="attachment-page-number">صفحة</label>
                    <input id="attachment-page-number" data-pdf-page type="number" min="1" max="1" value="1" inputmode="numeric" aria-label="رقم الصفحة" disabled>
                    <span>من <b data-pdf-page-count>—</b></span>
                    <button type="submit" class="lib-button lib-button--secondary" disabled>انتقال</button>
                </form>
                <button type="button" class="lib-button lib-button--secondary" data-pdf-next disabled>التالي</button>
            </div>
            <div class="lib-pdf-zoom">
                <label for="attachment-zoom">التكبير</label>
                <select id="attachment-zoom" data-pdf-zoom data-native-select disabled>
                    <option value="0.75">75%</option>
                    <option value="1" selected>ملاءمة العرض</option>
                    <option value="1.25">125%</option>
                    <option value="1.5">150%</option>
                    <option value="2">200%</option>
                    <option value="3">300%</option>
                </select>
                <button class="lib-button lib-button--secondary" type="button" data-pdf-exit-fullscreen hidden>إنهاء ملء الشاشة</button>
            </div>
        </div>
        <div class="lib-pdf-viewport" data-pdf-viewport aria-busy="true">
            <div class="lib-pdf-stage" data-pdf-stage></div>
            <div class="lib-pdf-loading" data-pdf-loading role="status" aria-live="polite">
                <span class="lib-pdf-spinner" aria-hidden="true"></span>
                <strong data-pdf-loading-label>جارٍ فتح المرفق…</strong>
                <span data-pdf-loading-detail>تُحمّل الصفحات المطلوبة عند قراءتها.</span>
            </div>
            <div class="lib-pdf-error" data-pdf-error role="alert" hidden>
                <strong>تعذّر عرض المرفق</strong>
                <p data-pdf-error-message></p>
                <button class="lib-button lib-button--secondary" type="button" data-pdf-retry>إعادة المحاولة</button>
                <a class="lib-button lib-button--primary" href="{{ $fileUrl }}?download=1">تنزيل الملف</a>
            </div>
        </div>
        <p class="lib-pdf-page-status" data-pdf-status role="status" aria-live="polite">جارٍ تجهيز القارئ</p>
        <noscript><p class="lib-upload-error">فعّل JavaScript لقراءة المرفق هنا، أو استخدم زر تنزيل الملف.</p></noscript>
    </div>
</main>
<script type="module" src="{{ asset('js/library-reader.js') }}?v=20260930-reader2"></script>
</body>
</html>
