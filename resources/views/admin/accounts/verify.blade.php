@php($cardTheme = preg_match('/^#[0-9a-f]{6}$/i', $schoolBranding['theme_color'] ?? '') ? $schoolBranding['theme_color'] : '#008C95')
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>التحقق من بطاقة الطالب</title>
    <style>body{font-family:Tahoma,Arial;background:#fff;color:#132f3b;padding:24px}.box{max-width:520px;margin:auto;background:#fff;border:2px solid {{ $cardTheme }};border-radius:14px;padding:24px}.logo{max-width:90px;max-height:70px;object-fit:contain}h1{color:{{ $cardTheme }};font-size:22px}.row{padding:8px 0;border-bottom:1px solid #f1f2f5}.label{color:#557078;display:inline-block;min-width:130px}</style>
</head>
<body><main class="box">
    <img class="logo" src="{{ \App\Support\SchoolBranding::logoUrl() }}" alt="شعار {{ $schoolBranding['school_name'] }}">
    <h1>{{ $schoolBranding['school_name'] }}</h1>
    <p>بطاقة طالب موثقة</p>
    <div class="row"><span class="label">اسم الطالب</span>{{ $student->user->name }}</div>
    <div class="row"><span class="label">الرقم الدراسي</span>{{ $student->student_number }}</div>
    <div class="row"><span class="label">الصف</span>{{ $student->classroom?->name }} {{ $student->classroom?->section }}</div>
    <div class="row"><span class="label">حالة الرابط</span>رابط تحقق موقّع وساري الصلاحية</div>
</main></body>
</html>
