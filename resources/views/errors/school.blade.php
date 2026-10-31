<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="{{ $schoolBranding['theme_color'] }}">
    <title>{{ $schoolBranding['school_name'] }} | {{ $heading }}</title>
    <link rel="icon" href="{{ route('school.pwa.icon') }}">
    <style>
        body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f3f7f5;color:#17343a;font-family:Cairo,Tahoma,Arial,sans-serif}
        main{width:min(92vw,480px);text-align:center;padding:36px 24px}
        img{width:88px;height:88px;object-fit:contain;margin:0 auto 20px}
        .code{font-size:14px;font-weight:700;color:#557078}
        h1{font-size:26px;margin:8px 0}
        p{line-height:1.8;color:#486168}
        a{display:inline-block;margin-top:12px;padding:11px 18px;background:#087e82;color:white;text-decoration:none;border-radius:6px;font-weight:700}
    </style>
</head>
<body>
<main>
    @if($schoolBranding['logo'])
        <img src="{{ asset('storage/'.$schoolBranding['logo']) }}" alt="شعار {{ $schoolBranding['school_name'] }}">
    @else
        <img src="{{ asset('icons/parent-icon-192.png') }}" alt="افهمها وفهمني">
    @endif
    <div class="code">{{ $statusCode }}</div>
    <h1>{{ $heading }}</h1>
    <p>{{ $message }}</p>
    <a href="{{ url('/') }}">العودة إلى الصفحة الرئيسية</a>
</main>
</body>
</html>