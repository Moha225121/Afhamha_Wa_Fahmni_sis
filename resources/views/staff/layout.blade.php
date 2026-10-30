<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    @include('shared.pwa-head')
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') | بوابة الموظفين</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v=staff">
    <link rel="stylesheet" href="{{ asset('css/staff.css') }}?v=3">
    @include('shared.portal-assets')
    @include('shared.branding-style')
    <script src="{{ asset('js/enhanced-selects.js') }}?v=2" defer></script>
</head>
<body>
    <a class="skip-link" href="#staff-main">تجاوز إلى المحتوى</a>
    <button class="menu-toggle" id="menu" aria-label="فتح القائمة">☰</button>

    <aside class="sidebar" id="sidebar">
        <a class="logo" href="{{ route('staff.dashboard') }}">
            <span class="brand-mark">@include('shared.brand-mark')</span>
            <span>
                <b>بوابة الموظفين</b>
                <small>{{ $schoolBranding['school_name'] }}</small>
            </span>
        </a>

        <nav>
            <p class="nav-section">مساحتي</p>
            <a href="{{ route('staff.dashboard') }}" class="{{ request()->routeIs('staff.dashboard') ? 'active' : '' }}">لوحة الموظف</a>
            <a href="{{ route('staff.ads.index') }}" class="{{ request()->routeIs('staff.ads.*') ? 'active' : '' }}">إعلانات المدرسة</a>
            @foreach (app(\App\Services\StaffAccess::class)->modules(auth()->user()) as $key => $module)
                @php($moduleUrl = $key === 'notifications' ? route('staff.notifications') : route('staff.module', $key))
                <a href="{{ $moduleUrl }}" class="{{ request()->route('module') === $key ? 'active' : '' }}">{{ $module['label'] }}</a>
            @endforeach
        </nav>

        <div class="partner">الشريك التقني<br><b>HEXA.Tech</b></div>
    </aside>

    <div class="page">
        <header class="topbar">
            <div class="account">
                <span>{{ auth()->user()->name }}</span>
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit">خروج</button>
                </form>
            </div>
        </header>

        <main class="content" id="staff-main" tabindex="-1">
            @include('shared.push-subscription')
            @if (session('success'))
                <div class="alert success">{{ session('success') }}</div>
            @endif

            @if ($errors->any())
                <div class="alert error">
                    <b>يرجى تصحيح الأخطاء:</b>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="page-head">
                <div>
                    <p class="eyebrow">بوابة الموظفين</p>
                    <h1>@yield('title')</h1>
                    <p class="muted">@yield('subtitle')</p>
                </div>
                @yield('actions')
            </div>

            @yield('content')
        </main>
    </div>

    <script>
        document.getElementById('menu').onclick = () => document.getElementById('sidebar').classList.toggle('open');
    </script>
    @stack('scripts')
</body>
</html>
