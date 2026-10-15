<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>بطاقات {{ $classroom->name }}</title>
    <style>
        *{box-sizing:border-box}body{margin:0;padding:24px;background:#eef3f6;color:#17324b;font-family:Tahoma,Arial,sans-serif}
        .toolbar{max-width:1100px;margin:0 auto 20px;display:flex;align-items:center;justify-content:space-between;gap:12px}.toolbar h1{margin:0 0 6px;font-size:24px}.toolbar p{margin:0;color:#6b7c88}
        .actions{display:flex;gap:8px;flex-wrap:wrap}.button{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 16px;border:0;border-radius:10px;background:#087f83;color:white;text-decoration:none;font:700 14px Tahoma,Arial,sans-serif;cursor:pointer}.button.secondary{background:#fff;color:#176c70;border:1px solid #cbdfe0}.class-picker{display:flex;gap:8px;align-items:center}.class-picker select{min-height:42px;min-width:190px;padding:8px 12px;border:1px solid #cbdfe0;border-radius:10px;background:#fff;color:#17324b;font:14px Tahoma,Arial,sans-serif}
        .cards{max-width:1100px;margin:auto;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
        .student-card{position:relative;min-height:245px;overflow:hidden;border:1px solid #d4dfe5;border-radius:18px;background:#fff;box-shadow:0 8px 24px #15324b12;break-inside:avoid;page-break-inside:avoid}
        .brand{display:flex;align-items:center;gap:12px;min-height:68px;padding:10px 16px;background:linear-gradient(110deg,#123b79 0 70%,#0d2b59 70%);border-bottom:4px solid #f7931d;color:#fff}.brand img{width:42px;height:42px;object-fit:contain;background:#fff;border-radius:50%;padding:3px}.school{font-size:14px;font-weight:bold}.label{margin-right:auto;color:#ffbd5a;font-size:12px;font-weight:bold}
        .content{display:grid;grid-template-columns:94px 1fr;gap:14px;padding:16px}.qr{width:82px;height:82px;object-fit:contain}.number{text-align:center;color:#123b79;font-weight:bold;font-size:12px;direction:ltr;margin-top:5px}.student-name{font-size:18px;line-height:1.45;color:#123b79;margin:0 0 8px}.line{display:flex;gap:8px;align-items:baseline;padding:4px 0;border-bottom:1px solid #edf1f3;font-size:12px}.line span{min-width:82px;color:#71818a}.line b{overflow-wrap:anywhere}.id{text-align:left;margin-top:8px;color:#60717e;font-size:11px;direction:ltr}
        @media(max-width:700px){body{padding:14px}.toolbar{align-items:flex-start;flex-direction:column}.actions{width:100%}.class-picker{width:100%}.class-picker select{min-width:0;flex:1}.cards{grid-template-columns:1fr}.student-card{min-height:230px}}
        @page{size:A4 portrait;margin:8mm}
        @media print{body{padding:0;background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}.toolbar{display:none}.cards{max-width:none;grid-template-columns:repeat(2,minmax(0,1fr));gap:4mm}.student-card{height:64mm;min-height:0;border-radius:3mm;box-shadow:none}.brand{height:16mm;min-height:16mm;padding:2mm 3mm}.brand img{width:10mm;height:10mm}.school{font-size:9pt}.label{font-size:8pt}.content{grid-template-columns:23mm 1fr;gap:3mm;padding:3mm}.qr{width:21mm;height:21mm}.number{font-size:7pt;margin-top:1mm}.student-name{font-size:11pt;margin:0 0 1mm}.line{font-size:7pt;padding:1mm 0}.line span{min-width:21mm}.id{font-size:6pt;margin-top:1mm}}
    </style>
</head>
<body>
    <header class="toolbar">
        <div>
            <h1>بطاقات {{ $classroom->name }}@if($classroom->section && $classroom->section !== 'أ') — {{ $classroom->section }}@endif</h1>
            <p>{{ $students->count() }} بطاقة — اطبع هذا الصف وحده أو اختر حفظ كملف PDF.</p>
        </div>
        <div class="actions">
            <a class="button secondary" href="{{ route('admin.students.cards',['classroom_id'=>$classroom->id]) }}">العودة إلى قائمة الصف</a>
            <form class="class-picker" method="get" action="#" onsubmit="event.preventDefault();if(this.class_route.value)window.location.href=this.class_route.value">
                <select name="class_route" aria-label="اختر الصف" required>
                    @foreach($classrooms as $optionClassroom)
                        <option value="{{ route('admin.students.cards.class',$optionClassroom) }}" @selected($optionClassroom->id===$classroom->id)>{{ $optionClassroom->name }}@if($optionClassroom->section && $optionClassroom->section !== 'أ') — {{ $optionClassroom->section }}@endif</option>
                    @endforeach
                </select>
                <button class="button secondary" type="submit">اختيار الصف</button>
            </form>
            <button class="button print-class" type="button">طباعة بطاقات الصف</button>
            <a class="button secondary" href="{{ route('admin.students.cards.class.pdf', $classroom) }}">تنزيل PDF</a>
        </div>
    </header>
    <main class="cards">
        @foreach($students as $student)
            <article class="student-card">
                <header class="brand">
                    @if($schoolBranding['logo'])<img src="{{ asset('storage/'.$schoolBranding['logo']) }}" alt="شعار المدرسة">@endif
                    <span class="school">{{ $schoolBranding['school_name'] }}</span>
                    <span class="label">بطاقة طالب</span>
                </header>
                <div class="content">
                    <div><img class="qr" src="{{ route('admin.students.cards.qr', $student) }}" loading="lazy" alt="رمز التحقق"><div class="number">{{ $student->student_number }}</div></div>
                    <div>
                        <h2 class="student-name">{{ $student->user->name }}</h2>
                        <div class="line"><span>الرقم الدراسي</span><b dir="ltr">{{ $student->student_number }}</b></div>
                        <div class="line"><span>الصف</span><b>{{ $student->classroom?->name }}@if($student->classroom?->section && $student->classroom->section !== 'أ') — {{ $student->classroom->section }}@endif</b></div>
                        <div class="line"><span>العام الدراسي</span><b>{{ $student->classroom?->academicYear?->name ?: '—' }}</b></div>
                        <div class="id">ID: NS-{{ $student->student_number }}</div>
                    </div>
                </div>
            </article>
        @endforeach
    </main>
    <script>
        document.querySelector('.print-class')?.addEventListener('click', async () => {
            const images = [...document.querySelectorAll('.qr')];
            images.forEach(image => { image.loading = 'eager'; });
            await Promise.all(images.map(image => image.complete ? Promise.resolve() : new Promise(resolve => {
                image.addEventListener('load', resolve, { once: true });
                image.addEventListener('error', resolve, { once: true });
            })));
            window.print();
        });
    </script>
</body>
</html>
