@extends('teacher.layout')
@section('title', 'رسالة جديدة')
@section('subtitle', 'اكتب الرسالة، ثم اختر الطلاب أو أولياء الأمور الذين ستصلهم.')
@section('actions')<a class="btn secondary" href="{{ route('teacher.notifications.sent') }}">الرسائل المرسلة</a>@endsection
@section('content')
<link rel="stylesheet" href="{{ asset('css/teacher-messaging.css') }}">
<div class="tm-workspace tm-simple">
    <section class="tm-compose-card" aria-labelledby="tm-compose-heading">
        <div class="tm-section-head"><h2 id="tm-compose-heading"><span aria-hidden="true">١</span> اكتب رسالتك</h2></div>
        <form id="tm-compose" method="post" action="{{ route('teacher.notifications.preview') }}">
            @csrf
            <div id="tm-selection-fields" hidden><input type="hidden" name="audience" value="families"><input type="hidden" name="selection_mode" value="branches"></div>
            <label for="tm-message">نص الرسالة</label>
            <textarea id="tm-message" name="message" rows="4" placeholder="اكتب رسالتك هنا…" required>{{ $draftMessage }}</textarea>
            <div class="tm-message-meta"><span class="tm-counter" id="tm-counter">0 حرف</span></div>
            <details class="tm-title-details"><summary>إضافة عنوان (اختياري)</summary><label for="tm-title">عنوان الرسالة</label><input id="tm-title" name="title" maxlength="60" placeholder="رسالة من {{ auth()->user()->name }}" value="{{ $draftTitle }}"></details>
        </form>
    </section>

    <section class="tm-recipient-card" aria-labelledby="tm-recipient-heading">
        <div class="tm-section-head"><h2 id="tm-recipient-heading"><span aria-hidden="true">٢</span> اختر المستلمين</h2><button type="button" id="tm-clear" class="tm-text-button">إلغاء التحديد</button></div>
        <div class="tm-audience-toggle" aria-label="نوع المستلمين">
            <button type="button" data-audience="families" aria-pressed="true">الطلاب وأولياء أمورهم</button>
            <button type="button" data-audience="parents" aria-pressed="false">أولياء الأمور فقط</button>
        </div>
        <div class="tm-search-field"><label id="tm-search-label" for="tm-search">ابحث عن طالب</label><input type="search" id="tm-search" placeholder="اكتب اسم الطالب للبحث والاختيار" autocomplete="off"><small>يمكنك البحث عن أكثر من اسم واختيارهم تباعًا.</small></div>
        <div class="tm-target-tabs" aria-label="طريقة اختيار المستلمين">
            <button type="button" data-mode="branches" aria-pressed="true">اختيار صفوف وشعب</button>
            <button type="button" data-mode="people" aria-pressed="false">اختيار أسماء</button>
        </div>
        <div id="tm-groups-panel">
            <div class="tm-recipient-tools"><p class="tm-helper">اختر صفًا أو أكثر، أو حدّد الشعب التي تريدها من كل صف.</p><button type="button" id="tm-select-grades" class="tm-text-button">تحديد جميع صفوفي</button></div>
            <div id="tm-grade-list" class="tm-grade-list" aria-label="الصفوف والشعب الموجودة"></div>
        </div>
        <div id="tm-people-panel" hidden>
            <div class="tm-recipient-tools"><span class="tm-helper" id="tm-visible-count"></span><button type="button" id="tm-select-all" class="tm-text-button">تحديد نتائج البحث</button></div>
            <div id="tm-selected-people" class="tm-selected-people" aria-label="الأسماء المحددة"></div>
            <div id="tm-people" class="tm-people-list"></div>
        </div>
        <p class="tm-helper" id="tm-rule">يتلقى ولي الأمر المرتبط نسخة واحدة توضح أسماء أبنائه المعنيين.</p>
    </section>

    <div class="tm-selection-bar">
        <div class="tm-summary-counts" role="status" aria-live="polite"><strong id="tm-summary">لم تختر مستلمين بعد</strong><p id="tm-selection-details">اختر صفوفًا أو ابحث عن أسماء محددة.</p><p class="tm-error" id="tm-error" hidden></p></div>
        <button type="submit" form="tm-compose" class="tm-send" id="tm-preview" disabled>مراجعة الرسالة والمستلمين ←</button>
    </div>
    <p class="tm-helper tm-phone-note">يمكن تفعيل إشعارات الهاتف من صفحة الإشعارات لدى الطالب وولي الأمر.</p>
    @env('local')<p class="tm-local-note">معاينة محلية ببيانات تجريبية</p>@endenv
    <noscript><p class="alert">يرجى تفعيل JavaScript لاختيار المستلمين ومراجعة الرسالة.</p></noscript>
</div>
<script id="tm-data" type="application/json">{!! json_encode($messagingData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
@endsection
@section('scripts')<script type="module" src="{{ asset('js/teacher-messaging.js') }}"></script>@endsection
