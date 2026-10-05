<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#087e86">
    <title>تعذّر الاتصال | التقنية السداسية</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;min-height:100svh;display:grid;place-items:center;padding:28px 16px;background:#f2f7f7;color:#143b49;font-family:Tahoma,Arial,sans-serif;text-align:center}
        .card{width:min(100%,520px);padding:32px;border:1px solid #dce8e8;border-top:4px solid #0c9c95;border-radius:24px;background:#fff;box-shadow:0 18px 55px #18384512}
        .logo{display:block;width:min(100%,290px);height:auto;margin:0 auto 12px}
        .company{font-size:14px;line-height:1.8;margin:0;color:#32616b}
        .slogan{font-size:20px;font-weight:700;line-height:1.8;margin:8px 0 2px;color:#087e86}
        .city{font-size:13px;color:#647d84;margin:0}
        .status{margin-top:24px;padding-top:24px;border-top:1px solid #e4eded}
        h1{font-size:24px;line-height:1.5;margin:0 0 10px}
        .intro{font-size:14px;line-height:1.9;color:#617582;margin:0}
        ol{list-style:none;counter-reset:steps;text-align:right;padding:0;margin:22px 0}
        li{counter-increment:steps;display:flex;align-items:flex-start;gap:12px;font-size:14px;line-height:1.9;margin:12px 0;color:#425f69}
        li::before{content:counter(steps);flex:0 0 28px;height:28px;display:grid;place-items:center;border-radius:50%;background:#eaf7f4;color:#087e86;font-weight:700;font-size:13px}
        button{min-height:48px;width:100%;padding:12px 24px;border:0;border-radius:12px;background:#087e86;color:#fff;font:inherit;font-weight:700;cursor:pointer}
        button:hover{background:#066570}
        button:focus-visible{outline:3px solid #164657;outline-offset:4px}
        @media(max-width:420px){.card{padding:24px 20px}.slogan{font-size:18px}h1{font-size:22px}}
    </style>
</head>
<body>
<main class="card">
    <img class="logo" src="/icons/hexa-tech-logo.png" width="546" height="240" alt="شعار شركة التقنية السداسية لتقنيات الذكاء الاصطناعي">
    <p class="company">شركة التقنية السداسية لتقنيات الذكاء الاصطناعي</p>
    <p class="slogan">نبتكر حلولا لنصنع بها اثر</p>
    <p class="city">موقع الشركة: بنغازي، ليبيا</p>
    <section class="status" aria-labelledby="offline-title">
        <h1 id="offline-title">تعذّر الاتصال بالنظام</h1>
        <p class="intro">أنت غير متصل بالنظام حالياً. اتبع الخطوات التالية لاستعادة الاتصال:</p>
        <ol>
            <li>تأكّد من اتصال جهازك بالإنترنت، ومن تشغيل شبكة Wi-Fi أو بيانات الهاتف.</li>
            <li>جرّب فتح موقع آخر للتأكّد من عمل الإنترنت، ثم أعد المحاولة. ظهور هذه الشاشة لا يعني بالضرورة وجود مشكلة في النظام.</li>
            <li>إذا استمرت المشكلة رغم عمل الإنترنت، تواصل مع الدعم المدرسي للتأكّد من حالة النظام، وأخبرهم بوقت ظهور المشكلة.</li>
        </ol>
        <button type="button" onclick="location.reload()">إعادة المحاولة</button>
    </section>
</main>
</body>
</html>