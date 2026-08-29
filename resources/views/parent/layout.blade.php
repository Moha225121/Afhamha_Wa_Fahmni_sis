<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0e7c86">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="ولي الأمر">
    <title>@yield('title', 'بوابة ولي الأمر') | افهمها وفهمني</title>
    <link rel="manifest" href="{{ route('parent.pwa.manifest') }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" href="{{ asset('icons/parent-icon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('icons/parent-icon-192.png') }}">
    <link rel="stylesheet" href="{{ asset('css/parent.css') }}?v=portal-style-1">
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
        <div class="header-actions">
            <button type="button" class="more-menu-toggle" data-more-toggle aria-expanded="false" aria-controls="parent-more-menu">
                <span class="more-menu-toggle-icon" aria-hidden="true">☰</span>
                <span>المزيد</span>
            </button>
        </div>
    </header>

    <div class="more-menu-backdrop" data-more-backdrop hidden></div>
    <nav class="more-menu-panel" id="parent-more-menu" aria-label="روابط المزيد" hidden>
        <div class="more-menu-head">
            <strong>المزيد</strong>
            <button type="button" data-more-close aria-label="إغلاق">×</button>
        </div>
        <div class="more-list">
            <a href="{{ route('parent.profile') }}" class="{{ request()->routeIs('parent.profile') ? 'active' : '' }}">
                <span aria-hidden="true">◉</span>
                الملف الشخصي
            </a>
            <a href="{{ route('parent.children.index') }}" class="{{ request()->routeIs('parent.children.*') ? 'active' : '' }}">
                <span aria-hidden="true">◱</span>
                أبنائي
            </a>
            <a href="{{ route('parent.messages') }}" class="{{ request()->routeIs('parent.messages') ? 'active' : '' }}">
                <span aria-hidden="true">✉</span>
                الرسائل
            </a>
            <a href="{{ route('parent.attendance') }}" class="{{ request()->routeIs('parent.attendance') ? 'active' : '' }}">
                <span aria-hidden="true">✓</span>
                الحضور
            </a>
            <a href="{{ route('parent.assignments') }}" class="{{ request()->routeIs('parent.assignments') ? 'active' : '' }}">
                <span aria-hidden="true">▤</span>
                الواجبات
            </a>
            <a href="{{ route('parent.exams') }}" class="{{ request()->routeIs('parent.exams') ? 'active' : '' }}">
                <span aria-hidden="true">○</span>
                الاختبارات والنتائج
            </a>
            <a href="{{ route('parent.notifications') }}" class="{{ request()->routeIs('parent.notifications') ? 'active' : '' }}">
                <span aria-hidden="true">●</span>
                الإشعارات
            </a>
            <form method="post" action="{{ route('logout') }}">
                @csrf
                <button type="submit"><span aria-hidden="true">↶</span> تسجيل الخروج</button>
            </form>
        </div>
    </nav>

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
    </nav>
</div>

<script>
const moreToggle = document.querySelector('[data-more-toggle]');
const moreMenu = document.getElementById('parent-more-menu');
const moreBackdrop = document.querySelector('[data-more-backdrop]');
const moreClose = document.querySelector('[data-more-close]');

if (moreToggle && moreMenu && moreBackdrop) {
    const closeMoreMenu = () => {
        moreMenu.classList.remove('open');
        moreBackdrop.classList.remove('open');
        moreToggle.setAttribute('aria-expanded', 'false');
        moreToggle.classList.remove('active');

        window.setTimeout(() => {
            if (! moreMenu.classList.contains('open')) {
                moreMenu.hidden = true;
                moreBackdrop.hidden = true;
            }
        }, 220);
    };

    const openMoreMenu = () => {
        moreMenu.hidden = false;
        moreBackdrop.hidden = false;
        moreToggle.setAttribute('aria-expanded', 'true');
        moreToggle.classList.add('active');

        window.requestAnimationFrame(() => {
            moreMenu.classList.add('open');
            moreBackdrop.classList.add('open');
        });
    };

    moreToggle.addEventListener('click', () => {
        moreMenu.hidden ? openMoreMenu() : closeMoreMenu();
    });

    moreBackdrop.addEventListener('click', closeMoreMenu);
    moreClose?.addEventListener('click', closeMoreMenu);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeMoreMenu();
        }
    });
}

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('{{ asset('parent-sw.js') }}', { scope: '/parent/' }).catch(() => {});
    });
}
</script>
@stack('scripts')
</body>
</html>
