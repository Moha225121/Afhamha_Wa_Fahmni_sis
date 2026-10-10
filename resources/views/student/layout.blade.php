<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">@include('shared.pwa-head', ['manifestUrl' => route('student.pwa.manifest').'?v=20261010-student'])
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ strip_tags($__env->yieldContent('title', 'بوابة الطالب')) }} | افهمها وفهمني</title>
    <link rel="stylesheet" href="{{ asset('css/parent.css') }}?v=portal-style-1">
    <link rel="stylesheet" href="{{ asset('css/student.css') }}?v=20260827-1">
    @include('shared.portal-assets')@include('shared.branding-style')
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
            <button type="button" class="install-app-button" data-install-app>
                <span aria-hidden="true">⇩</span><span>تثبيت التطبيق</span>
            </button>
            <button type="button" class="more-menu-toggle" data-more-toggle aria-expanded="false" aria-controls="student-more-menu">
                <span class="more-menu-toggle-icon" aria-hidden="true">☰</span>
                <span>المزيد</span>
            </button>
        </div>
    </header>

    <div class="more-menu-backdrop" data-more-backdrop hidden></div>
    <nav class="more-menu-panel" id="student-more-menu" aria-label="روابط المزيد" hidden>
        <div class="more-menu-head">
            <strong>المزيد</strong>
            <button type="button" data-more-close aria-label="إغلاق">×</button>
        </div>
        <div class="more-list">
            <button type="button" data-install-app><span aria-hidden="true">⇩</span> تثبيت التطبيق</button>
            <a href="{{ route('student.profile') }}" class="{{ request()->routeIs('student.profile') ? 'active' : '' }}"><span aria-hidden="true">◉</span> الملف الشخصي</a>
            <a href="{{ route('student.assignments.index') }}" class="{{ request()->routeIs('student.assignments.*') ? 'active' : '' }}"><span aria-hidden="true">▤</span> الواجبات</a>
            <a href="{{ route('student.exams.index') }}" class="{{ request()->routeIs('student.exams.*') ? 'active' : '' }}"><span aria-hidden="true">○</span> الاختبارات</a>
            <a href="{{ route('student.schedule') }}" class="{{ request()->routeIs('student.schedule') ? 'active' : '' }}"><span aria-hidden="true">▦</span> الجدول الدراسي</a>
            <a href="{{ route('student.attendance') }}" class="{{ request()->routeIs('student.attendance') ? 'active' : '' }}"><span aria-hidden="true">✓</span> الحضور</a>
            <a href="{{ route('student.library.index') }}" class="{{ request()->routeIs('student.library.*') ? 'active' : '' }}"><span aria-hidden="true">▥</span> المكتبة</a>
            <a href="{{ route('student.tutor.index') }}" class="{{ request()->routeIs('student.tutor.*') ? 'active' : '' }}"><span aria-hidden="true">✦</span> المعلّم الذكي</a>
            <a href="{{ route('student.results') }}" class="{{ request()->routeIs('student.results') ? 'active' : '' }}"><span aria-hidden="true">◌</span> النتائج</a>
            <a href="{{ route('student.notifications') }}" class="{{ request()->routeIs('student.notifications') ? 'active' : '' }}"><span aria-hidden="true">●</span> الإشعارات</a>
            <a href="{{ route('student.messages') }}" class="{{ request()->routeIs('student.messages') ? 'active' : '' }}"><span aria-hidden="true">✉</span> الرسائل</a>
            <form method="post" action="{{ route('logout') }}">
                @csrf
                <button type="submit"><span aria-hidden="true">↶</span> تسجيل الخروج</button>
            </form>
        </div>
    </nav>

    <dialog class="install-dialog" id="student-install-dialog" aria-labelledby="student-install-dialog-title">
        <form method="dialog"><button class="install-dialog-close" aria-label="إغلاق">×</button></form>
        <h2 id="student-install-dialog-title">تثبيت تطبيق الطالب</h2>
        <p data-install-message>يمكن تثبيت التطبيق من قائمة المتصفح.</p>
        <ol data-install-steps></ol>
        <form method="dialog"><button class="install-dialog-done">حسنًا</button></form>
    </dialog>

    <main class="app-content" id="student-main" tabindex="-1">
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
