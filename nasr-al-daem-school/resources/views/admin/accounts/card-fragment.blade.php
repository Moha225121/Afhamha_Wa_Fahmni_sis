<div style="width:94mm;height:62mm;border:0.35mm solid #d4dfe5;border-radius:5mm;background:#fff;box-sizing:border-box;overflow:hidden;direction:rtl;color:#17324b;font-family:dejavusans,Tahoma,Arial,sans-serif">
    <div style="height:16mm;padding:2mm 3mm;background:#123b79;border-bottom:1mm solid #f7931d;border-radius:5mm 5mm 0 0;box-sizing:border-box;color:#fff">
        <table style="width:100%;height:12mm;border-collapse:collapse;direction:rtl">
            <tr>
                <td style="width:12mm;vertical-align:middle;text-align:right">
                    @if($pdfLogo)<img src="{{ $pdfLogo }}" alt="" style="width:10mm;height:10mm;vertical-align:middle;border-radius:50%">@endif
                </td>
                <td style="vertical-align:middle;text-align:right;color:#fff;font-size:9pt;font-weight:bold">{{ $schoolBranding['school_name'] }}</td>
                <td style="width:22mm;vertical-align:middle;text-align:left;color:#ffbd5a;font-size:8pt;font-weight:bold">بطاقة طالب</td>
            </tr>
        </table>
    </div>
    <table style="width:100%;border-collapse:collapse;table-layout:fixed;direction:rtl;margin-top:2mm">
        <tr style="vertical-align:top">
            <td style="width:23mm;padding:1mm 3mm;text-align:center">
                <img src="{{ $student->qr_image }}" alt="QR" style="width:21mm;height:21mm;vertical-align:top">
                <div style="margin-top:1mm;color:#123b79;font-size:7pt;font-weight:bold;direction:ltr">{{ $student->student_number }}</div>
            </td>
            <td style="padding:1mm 2mm 1mm 3mm;text-align:right;vertical-align:top">
                <div style="margin:0 0 1mm;color:#123b79;font-size:11pt;line-height:1.4;font-weight:bold">{{ $student->user->name }}</div>
                <div style="padding:1mm 0;border-bottom:0.2mm solid #edf1f3;font-size:7pt"><span style="display:inline-block;width:21mm;color:#71818a">الرقم الدراسي</span><b dir="ltr">{{ $student->student_number }}</b></div>
                <div style="padding:1mm 0;border-bottom:0.2mm solid #edf1f3;font-size:7pt"><span style="display:inline-block;width:21mm;color:#71818a">الصف</span><b>{{ $student->classroom?->name }}@if($student->classroom?->section && $student->classroom->section !== 'أ') — {{ $student->classroom->section }}@endif</b></div>
                <div style="padding:1mm 0;border-bottom:0.2mm solid #edf1f3;font-size:7pt"><span style="display:inline-block;width:21mm;color:#71818a">العام الدراسي</span><b>{{ $student->classroom?->academicYear?->name ?: '—' }}</b></div>
                <div style="margin-top:1mm;color:#60717e;text-align:left;font-size:6pt;direction:ltr">ID: NS-{{ $student->student_number }}</div>
            </td>
        </tr>
    </table>
</div>
