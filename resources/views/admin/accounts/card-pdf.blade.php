<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>بطاقة الطالب</title>
    <style>
        body { font-family: dejavusans; color: #101632; margin: 0; }
        .card { border: 1px solid #d5dce7; border-radius: 5mm; page-break-inside: avoid; }
        table { width: 100%; border-collapse: collapse; }
        .header { background: #101632; color: #fff; border-bottom: 2mm solid #E6C52A; }
        .header td { padding: 5mm; vertical-align: middle; }
        .school { font-size: 16pt; font-weight: bold; }
        .motto { font-size: 10pt; color: #EFD75C; }
        .title { font-size: 16pt; font-weight: bold; text-align: left; }
        .body td { padding: 6mm; vertical-align: top; }
        .portrait { width: 36mm; text-align: center; }
        .placeholder { height: 40mm; background: #edf2f7; color: #647188; text-align: center; padding-top: 12mm; }
        .name { font-size: 20pt; font-weight: bold; margin-bottom: 4mm; }
        .details td { padding: 3mm 0; border-bottom: 1px solid #e4e8ee; font-size: 10pt; }
        .details .label { color: #647188; width: 31mm; padding-left: 3mm; }
        .value { font-weight: bold; }
    </style>
</head>
<body>
<div class="card">
    <table class="header"><tr>
        <td style="width:20mm"><img src="{{ $pdfLogo }}" alt="شعار {{ $schoolBranding['school_name'] }}" style="width:18mm;height:18mm"></td>
        <td><div class="school">{{ $schoolBranding['school_name'] }}</div><div class="motto">التعليم كما يجب أن يكون</div></td>
        <td class="title">بطاقة الطالب</td>
    </tr></table>
    <table class="body"><tr>
        <td class="portrait">
            @if($student->photo_image)
                <img src="{{ $student->photo_image }}" alt="صورة الطالب" style="width:32mm;max-height:40mm">
            @else
                <div class="placeholder">صورة الطالب</div>
            @endif
            @if($student->qr_image)
                <div style="margin-top:5mm"><img src="{{ $student->qr_image }}" alt="QR" style="width:22mm;height:22mm"></div>
            @endif
        </td>
        <td>
            <div class="name">{{ $student->user->name }}</div>
            <table class="details">
                <tr><td class="label">الرقم الدراسي</td><td class="value" dir="ltr">{{ $student->student_number }}</td></tr>
                <tr><td class="label">البريد الإلكتروني</td><td class="value" dir="ltr">{{ $student->user->email }}</td></tr>
                <tr><td class="label">الصف</td><td class="value">{{ $student->classroom?->name }} {{ $student->classroom?->section }}</td></tr>
                <tr><td class="label">العام الدراسي</td><td class="value">{{ $student->classroom?->academicYear?->name ?: '—' }}</td></tr>
                <tr><td class="label">رقم الهوية</td><td class="value" dir="ltr">NS-{{ $student->student_number }}</td></tr>
            </table>
        </td>
    </tr></table>
</div>
</body>
</html>
