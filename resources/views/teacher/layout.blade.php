<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>@yield('title', 'بوابة المعلم') | افهمها وفهمني</title><link rel="stylesheet" href="{{ asset('css/parent.css') }}"></head>
<body><div class="app-shell"><header class="app-header"><a class="brand" href="{{ route('teacher.assignments.index') }}"><span class="brand-mark">أف</span><span class="brand-copy"><b>افهمها وفهمني</b><small>بوابة المعلم</small></span></a><form method="post" action="{{ route('logout') }}" class="logout-form">@csrf<button>خروج</button></form></header><main class="app-content">@yield('content')</main></div></body>
</html>
