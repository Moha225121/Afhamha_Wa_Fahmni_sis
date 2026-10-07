<div style="font-family:dejavusans,Tahoma,Arial;color:#101632;background:#fff;border:1px solid #F1F2F5;border-radius:5mm;padding:4mm;height:56mm;box-sizing:border-box;text-align:right;direction:rtl;border-top:12mm solid #101632;position:relative">
    <div style="background:#101632;color:#fff;padding:1mm;height:11mm;position:absolute;top:-12mm;right:0;left:0">
        <img src="{{ $pdfLogo ?? asset(\App\Support\SchoolBranding::LOGO) }}" alt="شعار مدرسة النصر الدائم" style="width:8mm;height:8mm;vertical-align:middle;margin-left:2mm">
        <span style="font-size:8pt;font-weight:bold">{{ $schoolBranding['school_name'] }}</span>
        <span style="float:left;font-size:9pt;color:#EFD75C;font-weight:bold">بطاقة الطالب</span>
    </div>
    @if($student->photo_image)<img src="{{ $student->photo_image }}" alt="صورة الطالب" style="float:right;width:22mm;height:25mm;object-fit:cover;margin:1.5mm 0 1mm 3mm;border-radius:2mm">@endif
    <div style="color:#101632;font-size:10pt;font-weight:bold;margin:1.5mm 0">{{ $student->user->name }}</div>
    <div style="font-size:6.5pt;line-height:1.5">الرقم الدراسي: <b dir="ltr">{{ $student->student_number }}</b></div>
    <div style="font-size:6.5pt;line-height:1.5">البريد الإلكتروني: <b dir="ltr">{{ $student->user->email }}</b></div>
    <div style="font-size:6.5pt;line-height:1.5">الصف: {{ $student->classroom?->name }} {{ $student->classroom?->section }}</div>
    <div style="font-size:6.5pt;line-height:1.5">ولي الأمر: {{ $student->guardians->pluck('user.name')->join('، ') ?: '—' }}</div>
    <div style="font-size:6.5pt;line-height:1.5">هاتف ولي الأمر: <b dir="ltr">{{ $student->guardians->pluck('user.phone')->filter()->join('، ') ?: '—' }}</b></div>
    <img src="{{ $student->qr_image }}" alt="QR" style="float:left;width:12mm;height:12mm;margin-top:1mm">
    <div style="font-size:5pt;color:#557078;margin-top:1mm">QR صالح لمدة سنة · بيانات مدرسية رسمية</div>
</div>
