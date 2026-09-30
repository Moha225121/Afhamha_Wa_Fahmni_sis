<div style="font-family:dejavusans,Tahoma,Arial;color:#132d57;background:#fff;border:1px solid #d4dbe5;border-radius:5mm;padding:4mm;height:56mm;box-sizing:border-box;text-align:right;direction:rtl;border-top:12mm solid #123b79;position:relative">
    <div style="background:#123b79;color:#fff;padding:1mm;height:11mm;position:absolute;top:-12mm;right:0;left:0">
        @if($pdfLogo)<img src="{{ $pdfLogo }}" alt="" style="width:8mm;height:8mm;vertical-align:middle;margin-left:2mm">@endif
        <span style="font-size:8pt;font-weight:bold">{{ $schoolBranding['school_name'] }}</span>
        <span style="float:left;font-size:9pt;color:#ffad3e;font-weight:bold">بطاقة الطالب</span>
    </div>
    <div style="color:#123b79;font-size:10pt;font-weight:bold;margin:1.5mm 0">{{ $student->user->name }}</div>
    <div style="font-size:6.5pt;line-height:1.5">الرقم الدراسي: <b dir="ltr">{{ $student->student_number }}</b></div>
    <div style="font-size:6.5pt;line-height:1.5">البريد الإلكتروني: <b dir="ltr">{{ $student->user->email }}</b></div>
    <div style="font-size:6.5pt;line-height:1.5">الصف: {{ $student->classroom?->name }} {{ $student->classroom?->section }}</div>
    <div style="font-size:6.5pt;line-height:1.5">ولي الأمر: {{ $student->guardians->pluck('user.name')->join('، ') ?: '—' }}</div>
    <div style="font-size:6.5pt;line-height:1.5">هاتف ولي الأمر: <b dir="ltr">{{ $student->guardians->pluck('user.phone')->filter()->join('، ') ?: '—' }}</b></div>
    <img src="{{ $student->qr_image }}" alt="QR" style="float:left;width:12mm;height:12mm;margin-top:1mm">
    <div style="font-size:5pt;color:#557078;margin-top:1mm">QR صالح لمدة سنة · بيانات مدرسية رسمية</div>
</div>
