<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">@include('shared.pwa-head', ['manifestUrl' => route('student.pwa.manifest').'?v=20261010-student'])
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
    <link rel="stylesheet" href="{{ asset('css/student.css') }}?v=20261017-reader-1">
    <link rel="preload" href="{{ asset('fonts/cairo-variable.ttf') }}" as="font" type="font/ttf" crossorigin>
    @include('shared.branding-style')@include('shared.portal-assets')
    <link rel="stylesheet" href="{{ asset('css/nisbia-student.css') }}?v=20261029-1">
    @stack('page-styles')
    <script src="{{ asset('js/student-portal.js') }}?v=20261029-1" defer></script>
    <link rel="stylesheet" href="{{ asset('css/student-notifications.css') }}?v=20261015-1">
    <link rel="stylesheet" href="{{ asset('css/student-install.css') }}?v=20261015-1">
    <script src="{{ asset('js/student-install.js') }}?v=20261015-1" defer></script>
    <script src="{{ asset('js/student-notifications.js') }}?v=20261015-1" defer></script>
    <script src="{{ asset('js/student-tutor.js') }}" defer></script>
    <script src="{{ asset('js/enhanced-selects.js') }}" defer></script>
</head>
<body class="student-portal @yield('page-class')">
<a class="skip-link" href="#student-main">تجاوز إلى المحتوى</a>
<div class="app-shell">
    <header class="app-header">
        <a class="brand" href="{{ route('student.dashboard') }}" aria-label="بوابة الطالب">
            <span class="brand-mark">@include('shared.brand-mark')</span>
            <span class="brand-copy">
                <b>{{ $schoolBranding['school_name'] }}</b>
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
        @if(session(\App\Services\ParentStudentSession::PARENT_ID))
            <aside class="section-gap" role="status" style="padding:1rem;border-radius:1rem;background:#fff3cd;color:#664d03">
                أنت تستخدم مساحة الطالب بإذن ولي الأمر.
                <form method="post" action="{{ route('student.return-to-parent') }}" style="display:inline">@csrf<button type="submit" class="btn secondary">العودة إلى حساب ولي الأمر</button></form>
            </aside>
        @endif
        @php
            $isStudentDashboard = request()->routeIs('student.dashboard');
            $studentDashboardUrl = route('student.dashboard');
            $previousUrl = url()->previous();
            $currentUrl = url()->current();
            $previousParts = parse_url($previousUrl);
            $currentParts = parse_url($currentUrl);
            $previousPath = is_array($previousParts) ? ($previousParts['path'] ?? '') : '';
            $currentPath = is_array($currentParts) ? ($currentParts['path'] ?? '') : '';
            $sameOrigin = is_array($previousParts)
                && is_array($currentParts)
                && strtolower((string) ($previousParts['scheme'] ?? '')) === strtolower((string) ($currentParts['scheme'] ?? ''))
                && strtolower((string) ($previousParts['host'] ?? '')) === strtolower((string) ($currentParts['host'] ?? ''))
                && ($previousParts['port'] ?? null) === ($currentParts['port'] ?? null);
            $previousRouteIsSafe = false;
            if ($sameOrigin && preg_match('#^/student(?:/|$)#', $previousPath) === 1) {
                try {
                    $previousRoute = app('router')->getRoutes()->match(
                        \Illuminate\Http\Request::create($previousUrl, 'GET'),
                    );
                    $previousRouteIsSafe = str_starts_with((string) $previousRoute->getName(), 'student.');
                } catch (\Throwable) {
                    $previousRouteIsSafe = false;
                }
            }
            $isStudentPrevious = $previousRouteIsSafe
                && rtrim($previousPath, '/') !== rtrim($currentPath, '/');
            $studentBackUrl = $isStudentPrevious ? $previousUrl : $studentDashboardUrl;
        @endphp
        <div class="student-back-row" aria-label="التنقل السابق">
            @if($isStudentDashboard)
                <button class="student-back-button" type="button" disabled aria-disabled="true">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                    <span>رجوع</span>
                </button>
            @else
                <a class="student-back-button" href="{{ $studentBackUrl }}">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                    <span>رجوع</span>
                </a>
            @endif
        </div>
        @yield('content')
    </main>

    <nav class="bottom-nav" aria-label="تنقل الطالب">
        <div class="nav-section-label">مساحتي الدراسية</div>
        <a href="{{ route('student.dashboard') }}" class="{{ request()->routeIs('student.dashboard') ? 'active' : '' }}" @if(request()->routeIs('student.dashboard')) aria-current="page" @endif>
            <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m4 11 8-7 8 7v9H4zM9 20v-5h6v5"/></svg></span>
            <span class="nav-label">الرئيسية</span>
        </a>
        <a href="{{ route('student.subjects.index') }}" class="{{ request()->routeIs('student.subjects.*', 'student.lessons.*') ? 'active' : '' }}" @if(request()->routeIs('student.subjects.*', 'student.lessons.*')) aria-current="page" @endif>
            <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 4h14v16H5zM8 8h8M8 12h8M8 16h5"/></svg></span>
            <span class="nav-label">المواد</span>
        </a>
        <div class="nav-section-label nav-secondary">التعلّم والمتابعة</div>
        <a class="nav-secondary {{ request()->routeIs('student.assignments.*') ? 'active' : '' }}" href="{{ route('student.assignments.index') }}" @if(request()->routeIs('student.assignments.*')) aria-current="page" @endif>
            <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 4h8M9 3h6v3H9zM6 5H4v16h16V5h-2M8 11h8M8 15h5"/></svg></span>
            <span class="nav-label">الواجبات</span>
        </a>
        <a class="nav-secondary {{ request()->routeIs('student.exams.*') ? 'active' : '' }}" href="{{ route('student.exams.index') }}" @if(request()->routeIs('student.exams.*')) aria-current="page" @endif>
            <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18M12 7v5l3 2"/></svg></span>
            <span class="nav-label">الاختبارات</span>
        </a>
        <a class="nav-secondary {{ request()->routeIs('student.schedule') ? 'active' : '' }}" href="{{ route('student.schedule') }}" @if(request()->routeIs('student.schedule')) aria-current="page" @endif>
            <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5h16v15H4zM8 3v4M16 3v4M4 9h16M8 13h3M13 13h3M8 17h3"/></svg></span>
            <span class="nav-label">الجدول</span>
        </a>
        <a class="nav-secondary {{ request()->routeIs('student.attendance') ? 'active' : '' }}" href="{{ route('student.attendance') }}" @if(request()->routeIs('student.attendance')) aria-current="page" @endif>
            <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 3v3M17 3v3M4 8h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1M8 13l2 2 5-5"/></svg></span>
            <span class="nav-label">الحضور</span>
        </a>
        <a class="nav-secondary {{ request()->routeIs('student.library.*') ? 'active' : '' }}" href="{{ route('student.library.index') }}" @if(request()->routeIs('student.library.*')) aria-current="page" @endif>
            <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5h16v15H4zM8 3v4M16 3v4M4 9h16M8 13h3M13 13h3M8 17h3"/></svg></span>
            <span class="nav-label">المكتبة</span>
        </a>
        <a class="nav-secondary {{ request()->routeIs('student.tutor.*') ? 'active' : '' }}" href="{{ route('student.tutor.index') }}" @if(request()->routeIs('student.tutor.*')) aria-current="page" @endif>
            <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m12 3 1.7 5.3L19 10l-5.3 1.7L12 17l-1.7-5.3L5 10l5.3-1.7zM19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z"/></svg></span>
            <span class="nav-label">المعلّم الذكي</span>
        </a>
        <div class="nav-section-label nav-secondary">النتائج والتواصل</div>
        <a class="{{ request()->routeIs('student.results') ? 'active' : '' }}" href="{{ route('student.results') }}" @if(request()->routeIs('student.results')) aria-current="page" @endif>
            <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 20V10M12 20V4M19 20v-7"/></svg></span>
            <span class="nav-label">النتائج</span>
        </a>
        <a class="nav-secondary {{ request()->routeIs('student.notifications') ? 'active' : '' }}" href="{{ route('student.notifications') }}" @if(request()->routeIs('student.notifications')) aria-current="page" @endif>
            <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg></span>
            <span class="nav-label">الإشعارات</span>
            <b class="student-notification-inline-badge" data-student-unread hidden></b>
        </a>
        <a class="{{ request()->routeIs('student.messages') ? 'active' : '' }}" href="{{ route('student.messages') }}" @if(request()->routeIs('student.messages')) aria-current="page" @endif>
            <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5h16v12H7l-3 3zM8 9h8M8 13h5"/></svg></span>
            <span class="nav-label">الرسائل</span>
        </a>
        <a class="nav-secondary {{ request()->routeIs('student.profile') ? 'active' : '' }}" href="{{ route('student.profile') }}" @if(request()->routeIs('student.profile')) aria-current="page" @endif>
            <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3"/><path d="M5 20a7 7 0 0 1 14 0"/></svg></span>
            <span class="nav-label">الملف</span>
        </a>
    </nav>
@include('shared.pwa-install')
</div>
<script>
const studentMoreToggle = document.querySelector('[data-more-toggle]');
const studentMoreMenu = document.getElementById('student-more-menu');
const studentMoreBackdrop = document.querySelector('[data-more-backdrop]');
const studentMoreClose = document.querySelector('[data-more-close]');
const studentInstallButtons = [...document.querySelectorAll('[data-install-app]')];
const studentInstallDialog = document.getElementById('student-install-dialog');
const studentInstallMessage = studentInstallDialog?.querySelector('[data-install-message]');
const studentInstallSteps = studentInstallDialog?.querySelector('[data-install-steps]');
let studentDeferredInstallPrompt = null;

const studentIsStandalone = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
const hideStudentInstallButtons = () => studentInstallButtons.forEach(button => button.hidden = true);
const showStudentInstallHelp = () => {
    if (!studentInstallDialog || !studentInstallMessage || !studentInstallSteps) return;
    const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
    const isAndroid = /android/i.test(navigator.userAgent);
    studentInstallMessage.textContent = isIOS ? 'لتثبيت التطبيق على iPhone أو iPad:' : 'لم يعرض المتصفح نافذة التثبيت التلقائي. يمكنك تثبيته يدويًا:';
    const steps = isIOS
        ? ['افتح هذه الصفحة في Safari.', 'اضغط زر المشاركة.', 'اختر «إضافة إلى الشاشة الرئيسية»، ثم اضغط «إضافة».']
        : isAndroid
            ? ['افتح قائمة المتصفح ⋮.', 'اختر «تثبيت التطبيق» أو «إضافة إلى الشاشة الرئيسية».', 'أكد التثبيت.']
            : ['افتح قائمة المتصفح.', 'اختر «تثبيت افهمها وفهمني» أو Apps ثم Install.', 'أكد التثبيت ليظهر التطبيق على سطح المكتب.'];
    studentInstallSteps.replaceChildren(...steps.map(text => { const item = document.createElement('li'); item.textContent = text; return item; }));
    studentInstallDialog.showModal();
};

window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    studentDeferredInstallPrompt = event;
    if (!studentIsStandalone()) studentInstallButtons.forEach(button => button.hidden = false);
});
studentInstallButtons.forEach(button => button.addEventListener('click', async () => {
    if (studentIsStandalone()) { hideStudentInstallButtons(); return; }
    if (!studentDeferredInstallPrompt) { showStudentInstallHelp(); return; }
    studentDeferredInstallPrompt.prompt();
    await studentDeferredInstallPrompt.userChoice;
    studentDeferredInstallPrompt = null;
}));
window.addEventListener('appinstalled', () => { studentDeferredInstallPrompt = null; hideStudentInstallButtons(); });
if (studentIsStandalone()) hideStudentInstallButtons();

if (studentMoreToggle && studentMoreMenu && studentMoreBackdrop) {
    const closeStudentMoreMenu = () => {
        studentMoreMenu.classList.remove('open');
        studentMoreBackdrop.classList.remove('open');
        studentMoreToggle.setAttribute('aria-expanded', 'false');
        studentMoreToggle.classList.remove('active');
        window.setTimeout(() => {
            if (!studentMoreMenu.classList.contains('open')) {
                studentMoreMenu.hidden = true;
                studentMoreBackdrop.hidden = true;
            }
        }, 220);
    };
    const openStudentMoreMenu = () => {
        studentMoreMenu.hidden = false;
        studentMoreBackdrop.hidden = false;
        studentMoreToggle.setAttribute('aria-expanded', 'true');
        studentMoreToggle.classList.add('active');
        window.requestAnimationFrame(() => {
            studentMoreMenu.classList.add('open');
            studentMoreBackdrop.classList.add('open');
        });
    };
    studentMoreToggle.addEventListener('click', () => studentMoreMenu.hidden ? openStudentMoreMenu() : closeStudentMoreMenu());
    studentMoreBackdrop.addEventListener('click', closeStudentMoreMenu);
    studentMoreClose?.addEventListener('click', closeStudentMoreMenu);
    document.addEventListener('keydown', event => { if (event.key === 'Escape') closeStudentMoreMenu(); });
}
</script>
</body>
</html>
