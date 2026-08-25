<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0f766e">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="ولي الأمر">
    <title>@yield('title', 'بوابة ولي الأمر') | افهمها وفهمني</title>
    <link rel="manifest" href="{{ route('parent.pwa.manifest') }}">
    <link rel="icon" href="{{ asset('icons/parent-icon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('icons/parent-icon-192.png') }}">
    <link rel="stylesheet" href="{{ asset('css/parent.css') }}">
    <script src="{{ asset('js/enhanced-selects.js') }}" defer></script>
</head>
<body>
<div class="app-shell">
    <header class="app-header">
        <a class="brand" href="{{ route('parent.dashboard') }}" aria-label="بوابة ولي الأمر">
            <span class="brand-mark">AWF</span>
            <span class="brand-copy">
                <b>افهمها وفهمني</b>
                <small>بوابة ولي الأمر</small>
            </span>
        </a>
        <form method="post" action="{{ route('logout') }}" class="logout-form">
            @csrf
            <button type="submit">خروج</button>
        </form>
    </header>

    <main class="app-content">
        @if(session('success'))
            <div class="notice success">{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <div class="notice error">{{ $errors->first() }}</div>
        @endif

        @yield('content')
    </main>

    <nav class="bottom-nav" aria-label="تنقل ولي الأمر">
        <a href="{{ route('parent.dashboard') }}" class="{{ request()->routeIs('parent.dashboard') ? 'active' : '' }}">
            <span>⌂</span>
            الرئيسية
        </a>
        <a href="{{ route('parent.children.index') }}" class="{{ request()->routeIs('parent.children.*') ? 'active' : '' }}">
            <span>◱</span>
            أبنائي
        </a>
        <a href="{{ route('parent.results') }}" class="{{ request()->routeIs('parent.results') ? 'active' : '' }}">
            <span>◌</span>
            النتائج
        </a>
        <a href="{{ route('parent.messages') }}" class="{{ request()->routeIs('parent.messages') ? 'active' : '' }}">
            <span>✉</span>
            الرسائل
        </a>
        <a href="{{ route('parent.more') }}" class="{{ request()->routeIs('parent.more') || request()->routeIs('parent.profile') ? 'active' : '' }}">
            <span>☰</span>
            المزيد
        </a>
    </nav>
</div>

<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('{{ asset('parent-sw.js') }}', { scope: '/parent/' }).catch(() => {});
    });
}
</script>
@stack('scripts')
</body>
</html>
