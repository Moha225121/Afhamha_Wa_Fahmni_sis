@extends($layout)
@section('title', $resource->title)
@section('content')
    <link rel="stylesheet" href="{{ asset('css/library.css') }}?v=20260930-reader2">
    @php
        $readerSubject = $resource->subject_name ?: $resource->subject?->name ?: 'المكتبة الرقمية';
        $readerPdf = $resource->mime_type === 'application/pdf' || strtolower(pathinfo($resource->original_name ?: $resource->file_path, PATHINFO_EXTENSION)) === 'pdf';
        $readerTeacherOnly = $resource->audience === 'teachers' || in_array($resource->book_type, ['teacher_guide', 'assessment']);
        $readerTypes = config('library.book_types', []);
        $readerGrades = config('library.grades', []);
    @endphp
    <article class="library-reader">
        <a class="lib-back-link" href="{{ route($routePrefix.'.index') }}"><span aria-hidden="true">→</span> العودة إلى المكتبة</a>
        <header class="lib-reader-header">
            @include('library.subject-icon', ['subjectName' => $readerSubject])
            <div><p class="lib-book-subject">{{ $readerSubject }}</p><h1>{{ $resource->title }}</h1><div class="lib-badges">@foreach($resource->grade_levels ?? [] as $grade)<span class="lib-badge lib-badge--grade">{{ $readerGrades[(int) $grade] ?? 'الصف '.$grade }}</span>@endforeach @if($resource->book_type)<span class="lib-badge">{{ $readerTypes[$resource->book_type] ?? $resource->category ?: 'مورد تعليمي' }}</span>@endif @if($resource->term)<span class="lib-badge">{{ $resource->term }}</span>@endif @if($resource->track)<span class="lib-badge">{{ $resource->track }}</span>@endif @if($readerTeacherOnly)<span class="lib-teacher-badge">للمعلمين فقط</span>@endif</div></div>
        </header>
        <div class="lib-reader-toolbar">
            <div class="lib-file-meta">@if($readerPdf)<span dir="ltr">PDF</span>@endif @if($resource->page_count)<span>{{ number_format($resource->page_count) }} صفحة</span>@endif @if($resource->file_size)<span dir="ltr">{{ number_format($resource->file_size / 1048576, 1) }} MB</span>@endif</div>
            <div class="lib-reader-actions"><a class="lib-button lib-button--primary" href="{{ route($routePrefix.'.download', $resource) }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/></svg>تنزيل الكتاب</a><a class="lib-button lib-button--secondary" href="{{ route($routePrefix.'.file', $resource) }}" target="_blank" rel="noopener">فتح مستقل <span aria-hidden="true">↗</span></a>@if($readerPdf)<button type="button" class="lib-button lib-button--secondary" data-library-fullscreen hidden>ملء الشاشة</button>@endif</div>
        </div>
        @if($readerPdf)
            <div class="lib-reader-canvas" data-library-reader-canvas data-pdf-url="{{ route($routePrefix.'.file', $resource) }}" data-pdf-assets="{{ asset('vendor/pdfjs') }}/" aria-label="قارئ {{ $resource->title }}" tabindex="0">
                <div class="lib-pdf-controls" aria-label="أدوات قراءة الكتاب">
                    <div class="lib-pdf-pages">
                        <button type="button" class="lib-button lib-button--secondary" data-pdf-previous disabled>السابق</button>
                        <form data-pdf-jump class="lib-pdf-jump"><label for="pdf-page-number">صفحة</label><input id="pdf-page-number" data-pdf-page type="number" min="1" max="1" value="1" inputmode="numeric" aria-label="رقم الصفحة" disabled><span>من <b data-pdf-page-count>—</b></span><button type="submit" class="lib-button lib-button--secondary" disabled>انتقال</button></form>
                        <button type="button" class="lib-button lib-button--secondary" data-pdf-next disabled>التالي</button>
                    </div>
                    <div class="lib-pdf-zoom"><label for="pdf-zoom">التكبير</label><select id="pdf-zoom" data-pdf-zoom data-native-select disabled><option value="0.75">75%</option><option value="1" selected>ملاءمة العرض</option><option value="1.25">125%</option><option value="1.5">150%</option><option value="2">200%</option><option value="3">300%</option></select><button class="lib-button lib-button--secondary" type="button" data-pdf-exit-fullscreen hidden>إنهاء ملء الشاشة</button></div>
                </div>
                <div class="lib-pdf-viewport" data-pdf-viewport aria-busy="true">
                    <div class="lib-pdf-stage" data-pdf-stage></div>
                    <div class="lib-pdf-loading" data-pdf-loading role="status" aria-live="polite"><span class="lib-pdf-spinner" aria-hidden="true"></span><strong data-pdf-loading-label>جارٍ فتح الكتاب…</strong><span data-pdf-loading-detail>تُحمّل الصفحات المطلوبة عند قراءتها.</span></div>
                    <div class="lib-pdf-error" data-pdf-error role="alert" hidden><strong>تعذّر عرض الكتاب</strong><p data-pdf-error-message></p><button class="lib-button lib-button--secondary" type="button" data-pdf-retry>إعادة المحاولة</button><a class="lib-button lib-button--primary" href="{{ route($routePrefix.'.download', $resource) }}">تنزيل الكتاب</a></div>
                </div>
                <p class="lib-pdf-page-status" data-pdf-status role="status" aria-live="polite">جارٍ تجهيز القارئ</p>
                <noscript><p class="lib-upload-error">فعّل JavaScript لقراءة الصفحات هنا، أو استخدم زر تنزيل الكتاب.</p></noscript>
            </div>
            <p class="lib-reader-help">استخدم السابق والتالي أو أدخل رقم الصفحة للانتقال مباشرة. يمكنك تنزيل الكتاب لقراءته دون اتصال.</p>
        @else
            <div class="lib-empty lib-reader-unavailable">@include('library.subject-icon', ['subjectName' => $readerSubject])<h2>هذا المورد متاح للتنزيل</h2><p>نزّل الملف لفتحه بالتطبيق المناسب على جهازك.</p><a class="lib-button lib-button--primary" href="{{ route($routePrefix.'.download', $resource) }}">تنزيل المورد</a></div>
        @endif
    </article>
    <script src="{{ asset('js/library-reader.js') }}?v=20260930-reader2" defer></script>
@endsection
