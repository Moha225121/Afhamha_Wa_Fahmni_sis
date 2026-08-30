<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>دخول المنصة | افهمها وفهمني</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('icons/parent-icon-192.png') }}">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v=login-style-1">
    @include('shared.branding-style')
</head>
<body class="login-page">
<main class="login-card">
    <div class="brand-mark">@include('shared.brand-mark')</div>
    <p class="eyebrow">منصة افهمها وفهمني</p>
    <h1>دخول المنصة</h1>

    <form method="post" action="{{ route('login.store') }}">
        @csrf
        <label>
            البريد الإلكتروني
            <input name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email">
        </label>
        <label>
            كلمة المرور
            <input name="password" type="password" required autocomplete="current-password">
        </label>
        <label class="check">
            <input type="checkbox" name="remember">
            تذكرني
        </label>
        @if($errors->any())
            <div class="alert error">{{ $errors->first() }}</div>
        @endif
        <button class="btn primary" type="submit">تسجيل الدخول</button>
    </form>
</main>
</body>
</html>
