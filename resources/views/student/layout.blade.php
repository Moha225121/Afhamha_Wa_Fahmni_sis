<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0b6b63">
    <title>{{ strip_tags($__env->yieldContent('title', 'بوابة الطالب')) }} | افهمها وفهمني</title>
    <link rel="stylesheet" href="{{ asset('css/parent.css') }}">
    <link rel="stylesheet" href="{{ asset('css/student.css') }}?v=20260825-2">
    <script src="{{ asset('js/student-tutor.js') }}" defer></script>
    <script src="{{ asset('js/enhanced-selects.js') }}" defer></script>
</head>
<body class="student-portal">
<a class="skip-link" href="#student-main">تجاوز إلى المحتوى</a>
<div class="app-shell">
    <header class="app-header">
        <a class="brand" href="{{ route('student.dashboard') }}" aria-label="بوابة الطالب">
            <span class="brand-mark">AWF</span>
            <span class="brand-copy">
                <b>افهمها وفهمني</b>
                <small>بوابة الطالب</small>
            </span>
        </a>
        <div class="header-page-context" aria-hidden="true">
            <span>مساحة التعلّم</span>
            <strong>{{ strip_tags($__env->yieldContent('title', 'بوابة الطالب')) }}</strong>
        </div>
        <div class="header-actions">
            <a class="header-notifications" href="{{ route('student.notifications') }}" aria-label="الإشعارات">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
            </a>
            <span class="header-avatar" title="{{ auth()->user()->name }}" aria-hidden="true">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
            <form method="post" action="{{ route('logout') }}" class="logout-form">
                @csrf
                <button type="submit" aria-label="تسجيل الخروج">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M15 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4"/></svg>
                    <span>خروج</span>
                </button>
            </form>
        </div>
    </header>

    <main class="app-content" id="student-main" tabindex="-1">
        @yield('content')
    </main>

    @php($dashboardNavigationActive = request()->routeIs('student.dashboard', 'student.assignments.*', 'student.exams.*', 'student.attendance', 'student.schedule', 'student.notifications', 'student.results', 'student.messages'))
    <nav class="bottom-nav" aria-label="تنقل الطالب">
        <span class="nav-section-label">القائمة الرئيسية</span>
        <a href="{{ route('student.dashboard') }}" class="{{ $dashboardNavigationActive ? 'active' : '' }}" @if($dashboardNavigationActive) aria-current="page" @endif>
            <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 11.5 12 4l9 7.5M5.5 10v10h13V10M9.5 20v-6h5v6"/></svg></span>
            <span class="nav-label">الرئيسية</span>
        </a>
        <a href="{{ route('student.subjects.index') }}" class="{{ request()->routeIs('student.subjects.*', 'student.lessons.*') ? 'active' : '' }}" @if(request()->routeIs('student.subjects.*', 'student.lessons.*')) aria-current="page" @endif>
            <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H11v16H6.5A2.5 2.5 0 0 0 4 21.5zM20 5.5A2.5 2.5 0 0 0 17.5 3H13v16h4.5a2.5 2.5 0 0 1 2.5 2.5z"/></svg></span>
            <span class="nav-label">المواد</span>
        </a>
        <a href="{{ route('student.library.index') }}" class="{{ request()->routeIs('student.library.*') ? 'active' : '' }}" @if(request()->routeIs('student.library.*')) aria-current="page" @endif>
            <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 4h5v16H4zM10 4h5v16h-5zM16.5 5l3.5-1 3.5 14-3.5 1z"/></svg></span>
            <span class="nav-label">المكتبة</span>
        </a>
        <a href="{{ route('student.tutor.index') }}" class="{{ request()->routeIs('student.tutor.*') ? 'active' : '' }}" @if(request()->routeIs('student.tutor.*')) aria-current="page" @endif>
            <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m12 3 1.5 4.5L18 9l-4.5 1.5L12 15l-1.5-4.5L6 9l4.5-1.5zM19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg></span>
            <span class="nav-label">المعلّم</span>
        </a>
        <a href="{{ route('student.profile') }}" class="{{ request()->routeIs('student.profile') ? 'active' : '' }}" @if(request()->routeIs('student.profile')) aria-current="page" @endif>
            <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4.5 21a7.5 7.5 0 0 1 15 0"/></svg></span>
            <span class="nav-label">الملف الشخصي</span>
        </a>
    </nav>
</div>
</body>
</html>
