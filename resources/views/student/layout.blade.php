<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0e7c86">
    <title>{{ strip_tags($__env->yieldContent('title', 'بوابة الطالب')) }} | افهمها وفهمني</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('icons/parent-icon-192.png') }}">
    <link rel="stylesheet" href="{{ asset('css/parent.css') }}?v=portal-style-1">
    <link rel="stylesheet" href="{{ asset('css/student.css') }}?v=20260827-1">
    @include('shared.branding-style')
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
        <form method="post" action="{{ route('logout') }}" class="logout-form">
            @csrf
            <button type="submit">خروج</button>
        </form>
    </header>

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
