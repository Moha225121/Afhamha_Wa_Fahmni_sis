<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>تعذر الوصول</title>
    <style>body{margin:0;background:#f5f8fa;font:16px Arial,sans-serif;color:#172b46;display:grid;min-height:100vh;place-items:center}main{box-sizing:border-box;width:min(92%,540px);padding:32px;border:1px solid #dce5ed;border-radius:18px;background:white}h1{font-size:24px}p{line-height:1.8;color:#52677b}button,a{font:inherit;display:inline-block;background:#087f83;color:white;padding:12px 18px;border:0;border-radius:10px;text-decoration:none;cursor:pointer}</style>
</head>
<body><main>
    @if(request()->is('supervisor/*') && auth()->check() && !auth()->user()->isSupervisor())
        <h1>سجّل الدخول بحساب المشرف</h1>
        <p>الحساب الحالي «{{ auth()->user()->name }}» ليس حساب مشرف. تسجيل الدخول بحساب آخر في أحد تبويبات هذا المتصفح يغيّر الحساب في بقية التبويبات أيضًا.</p>
        <form method="post" action="{{ route('logout') }}">@csrf<button type="submit">تسجيل الخروج وتبديل الحساب</button></form>
    @else
        <h1>لا تملك صلاحية الوصول</h1>
        <p>قد يكون الطالب خارج الفصول المكلّف بها حسابك. ارجع إلى القائمة واختر طالبًا ضمن نطاق إشرافك.</p>
        <a href="{{ url('/') }}">العودة إلى حسابي</a>
    @endif
</main></body></html>
