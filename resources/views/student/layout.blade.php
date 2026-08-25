<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f766e">
    <title>@yield('title', 'بوابة الطالب') | افهمها وفهمني</title>
    <link rel="stylesheet" href="{{ asset('css/parent.css') }}">
</head>
<body>
<div class="app-shell">
    <header class="app-header">
        <a class="brand" href="{{ route('student.dashboard') }}" aria-label="بوابة الطالب">
            <span class="brand-mark">أف</span>
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

    <main class="app-content">
        @yield('content')
    </main>

    <nav class="bottom-nav" aria-label="تنقل الطالب">
        <a href="{{ route('student.dashboard') }}" class="{{ request()->routeIs('student.dashboard') ? 'active' : '' }}">
            <span>⌂</span>
            الرئيسية
        </a>
        <a href="{{ route('student.results') }}" class="{{ request()->routeIs('student.results') ? 'active' : '' }}">
            <span>◌</span>
            النتائج
        </a>
        <a href="{{ route('student.messages') }}" class="{{ request()->routeIs('student.messages') ? 'active' : '' }}">
            <span>✉</span>
            الرسائل
        </a>
        <a href="{{ route('student.profile') }}" class="{{ request()->routeIs('student.profile') ? 'active' : '' }}">
            <span>◉</span>
            الملف
        </a>
    </nav>
</div>
</body>
</html>
