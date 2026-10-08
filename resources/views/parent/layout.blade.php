<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">@include('shared.pwa-head', ['manifestUrl' => route('parent.pwa.manifest').'?v=20261007-nasr-daem'])
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'بوابة ولي الأمر') | افهمها وفهمني</title>
    <link rel="stylesheet" href="{{ asset('css/parent.css') }}?v=parent-identity-1">
    @include('shared.portal-assets')@include('shared.branding-style')
    <script src="{{ asset('js/enhanced-selects.js') }}" defer></script>
</head>
<body>
<div class="app-shell">
    <header class="app-header">
        <a class="brand" href="{{ route('parent.dashboard') }}" aria-label="بوابة ولي الأمر">
            <span class="brand-mark">@include('shared.brand-mark')</span>
            <span class="brand-copy">
                <b>افهمها وفهمني</b>
                <small>{{ $parentIdentity['name'] ?? 'بوابة ولي الأمر' }}</small>
            </span>
        </a>
        <div class="header-actions">
            <button type="button" class="install-app-button" data-install-app>
                <span aria-hidden="true">⇩</span><span>تثبيت التطبيق</span>
            </button>
            @if(!request()->routeIs('parent.identity.*'))
            <button type="button" class="more-menu-toggle" data-more-toggle aria-expanded="false" aria-controls="parent-more-menu">
                <span class="more-menu-toggle-icon" aria-hidden="true">☰</span>
                <span>المزيد</span>
            </button>
            @else
            <form method="post" action="{{ route('logout') }}">@csrf<button type="submit" class="more-menu-toggle">خروج</button></form>
            @endif
        </div>
    </header>

    <div class="more-menu-backdrop" data-more-backdrop hidden></div>
    <nav class="more-menu-panel" id="parent-more-menu" aria-label="روابط المزيد" hidden>
        <div class="more-menu-head">
            <strong>المزيد</strong>
            <button type="button" data-more-close aria-label="إغلاق">×</button>
        </div>
        <div class="more-list">
            <button type="button" data-install-app><span aria-hidden="true">⇩</span> تثبيت التطبيق</button>
            @if(count($parentIdentityOptions ?? []) > 1)
                <a href="{{ route('parent.identity.create') }}"><span aria-hidden="true">⇄</span> تغيير مستخدم المنصة</a>
            @endif
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
            <a href="{{ route('parent.guardian-calls') }}"><span aria-hidden="true">!</span> استدعاءات ولي الأمر</a>
            <a href="{{ route('parent.student-followup') }}"><span aria-hidden="true">◇</span> متابعة الطالب</a>
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

    <dialog class="install-dialog" id="parent-install-dialog" aria-labelledby="install-dialog-title">
        <form method="dialog"><button class="install-dialog-close" aria-label="إغلاق">×</button></form>
        <h2 id="install-dialog-title">تثبيت تطبيق ولي الأمر</h2>
        <p data-install-message>يمكن تثبيت التطبيق من قائمة المتصفح.</p>
        <ol data-install-steps></ol>
        <form method="dialog"><button class="install-dialog-done">حسنًا</button></form>
    </dialog>

    <main class="app-content">
        @if(session('success'))
            <div class="notice success">{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <div class="notice error">{{ $errors->first() }}</div>
        @endif

        @yield('content')
    </main>

    @if(!request()->routeIs('parent.identity.*'))
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
    @endif
</div>

<script>
const moreToggle = document.querySelector('[data-more-toggle]');
const moreMenu = document.getElementById('parent-more-menu');
const moreBackdrop = document.querySelector('[data-more-backdrop]');
const moreClose = document.querySelector('[data-more-close]');
const installButtons = [...document.querySelectorAll('[data-install-app]')];
const installDialog = document.getElementById('parent-install-dialog');
const installMessage = installDialog?.querySelector('[data-install-message]');
const installSteps = installDialog?.querySelector('[data-install-steps]');
let deferredInstallPrompt = null;

const isStandalone = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
const hideInstallButtons = () => installButtons.forEach(button => button.hidden = true);
const showInstallHelp = () => {
    const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
    const isAndroid = /android/i.test(navigator.userAgent);
    installMessage.textContent = isIOS ? 'لتثبيت التطبيق على iPhone أو iPad:' : 'لم يعرض المتصفح نافذة التثبيت التلقائي. يمكنك تثبيته يدويًا:';
    const steps = isIOS
        ? ['افتح هذه الصفحة في Safari.', 'اضغط زر المشاركة.', 'اختر «إضافة إلى الشاشة الرئيسية»، ثم اضغط «إضافة».']
        : isAndroid
            ? ['افتح قائمة المتصفح ⋮.', 'اختر «تثبيت التطبيق» أو «إضافة إلى الشاشة الرئيسية».', 'أكد التثبيت.']
            : ['افتح قائمة المتصفح.', 'اختر «تثبيت افهمها وفهمني» أو Apps ثم Install.', 'أكد التثبيت ليظهر التطبيق على سطح المكتب.'];
    installSteps.replaceChildren(...steps.map(text => { const item = document.createElement('li'); item.textContent = text; return item; }));
    installDialog.showModal();
};

window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    deferredInstallPrompt = event;
    if (! isStandalone()) installButtons.forEach(button => button.hidden = false);
});

installButtons.forEach(button => button.addEventListener('click', async () => {
    if (isStandalone()) { hideInstallButtons(); return; }
    if (! deferredInstallPrompt) { showInstallHelp(); return; }
    deferredInstallPrompt.prompt();
    await deferredInstallPrompt.userChoice;
    deferredInstallPrompt = null;
}));

window.addEventListener('appinstalled', () => { deferredInstallPrompt = null; hideInstallButtons(); });
if (isStandalone()) hideInstallButtons();

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
@include('shared.pwa-install')
</body>
</html>
