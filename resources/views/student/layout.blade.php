<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0e7c86">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="بوابة الطالب">
    <title>{{ strip_tags($__env->yieldContent('title', 'بوابة الطالب')) }} | افهمها وفهمني</title>
    <link rel="manifest" href="{{ route('student.pwa.manifest') }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('icons/parent-icon-192.png') }}">
    <link rel="stylesheet" href="{{ asset('css/parent.css') }}?v=portal-style-1">
    <link rel="stylesheet" href="{{ asset('css/student.css') }}?v=20260827-1">
    @include('shared.branding-style')@include('shared.portal-assets')
    <link rel="stylesheet" href="{{ asset('css/student-notifications.css') }}?v=20261015-1">
    <link rel="stylesheet" href="{{ asset('css/student-install.css') }}?v=20261015-1">
    <script src="{{ asset('js/student-install.js') }}?v=20261015-1" defer></script>
    <script src="{{ asset('js/student-notifications.js') }}?v=20261015-1" defer></script>
    <script src="{{ asset('js/student-tutor.js') }}" defer></script>
    <script src="{{ asset('js/enhanced-selects.js') }}" defer></script>
</head>
<body class="student-portal">
<a class="skip-link" href="#student-main">تجاوز إلى المحتوى</a>
<div class="app-shell">
    <header class="app-header">
        <a class="brand" href="{{ route('student.dashboard') }}" aria-label="بوابة الطالب">
            <span class="brand-mark">@include('shared.brand-mark')</span>
            <span class="brand-copy">
                <b>افهمها وفهمني</b>
                <small>بوابة الطالب</small>
            </span>
        </a>
        <div class="header-actions">
            <button type="button" class="install-app-button student-install-button" data-install-app aria-haspopup="dialog" aria-controls="student-install-dialog">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m-5-5 5 5 5-5M5 16v5h14v-5"/></svg>
                <span>تثبيت التطبيق</span>
            </button>
            @include('student.partials.notification-bell')
            <form method="post" action="{{ route('logout') }}" class="logout-form">
                @csrf
                <button type="submit">خروج</button>
            </form>
        </div>
    </header>

    <dialog class="install-dialog" id="student-install-dialog" aria-labelledby="student-install-title" data-service-worker-url="{{ route('student.pwa.service-worker') }}" data-service-worker-scope="/student/">
        <form method="dialog"><button class="install-dialog-close" aria-label="إغلاق">×</button></form>
        <h2 id="student-install-title">تثبيت تطبيق الطالب</h2>
        <p data-install-message>يمكن تثبيت التطبيق من قائمة المتصفح.</p>
        <ol data-install-steps></ol>
        <form method="dialog"><button class="install-dialog-done">حسنًا</button></form>
    </dialog>

    <main class="app-content" id="student-main" tabindex="-1">
        @yield('content')
    </main>

    <nav class="bottom-nav" aria-label="تنقل الطالب">
        <a href="{{ route('student.dashboard') }}" class="{{ request()->routeIs('student.dashboard') ? 'active' : '' }}" @if(request()->routeIs('student.dashboard')) aria-current="page" @endif>
            <span aria-hidden="true">⌂</span>
            الرئيسية
        </a>
        <a href="{{ route('student.subjects.index') }}" class="{{ request()->routeIs('student.subjects.*', 'student.lessons.*') ? 'active' : '' }}" @if(request()->routeIs('student.subjects.*', 'student.lessons.*')) aria-current="page" @endif>
            <span aria-hidden="true">▤</span>
            المواد
        </a>
        <a href="{{ route('student.library.index') }}" class="{{ request()->routeIs('student.library.*') ? 'active' : '' }}" @if(request()->routeIs('student.library.*')) aria-current="page" @endif>
            <span aria-hidden="true">▦</span>
            المكتبة
        </a>
        <a href="{{ route('student.tutor.index') }}" class="{{ request()->routeIs('student.tutor.*') ? 'active' : '' }}" @if(request()->routeIs('student.tutor.*')) aria-current="page" @endif>
            <span aria-hidden="true">✦</span>
            المعلّم
        </a>
        <a href="{{ route('student.profile') }}" class="{{ request()->routeIs('student.profile') ? 'active' : '' }}" @if(request()->routeIs('student.profile')) aria-current="page" @endif>
            <span aria-hidden="true">◉</span>
            الملف
        </a>
    </nav>
</div>
</body>
</html>
