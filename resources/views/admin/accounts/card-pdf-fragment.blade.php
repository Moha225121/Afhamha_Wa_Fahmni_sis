@php($cardTheme = preg_match('/^#[0-9a-f]{6}$/i', $schoolBranding['theme_color'] ?? '') ? $schoolBranding['theme_color'] : '#008C95')
<table width="84mm" height="53mm" border="1" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:0.35mm solid #d5dce7;background-color:#ffffff;color:{{ $cardTheme }};font-family:dejavusans,Tahoma,Arial,sans-serif;direction:rtl">
    <tbody>
        <tr height="9mm" style="background-color:{{ $cardTheme }};color:#ffffff">
            <td width="18mm" align="center" valign="middle" style="padding:0.5mm;background-color:{{ $cardTheme }};color:#ffffff">
                @if($pdfLogo)<img src="{{ $pdfLogo }}" width="7mm" height="7mm" alt="">@endif
            </td>
            <td width="55mm" valign="middle" style="padding:0.5mm;background-color:{{ $cardTheme }};color:#ffffff;font-size:7pt;font-weight:bold">
                {{ $schoolBranding['school_name'] }}<br><span style="font-size:5pt;color:#FFFFFF">التعليم كما يجب أن يكون</span>
            </td>
            <td width="11mm" align="center" valign="middle" style="padding:0.5mm;background-color:{{ $cardTheme }};color:#FFFFFF;font-size:5.5pt;font-weight:bold">بطاقة الطالب</td>
        </tr>
        <tr height="44mm">
            <td width="18mm" align="center" valign="top" style="padding:0.5mm">
                @if($student->photo_image)<img src="{{ $student->photo_image }}" width="16mm" height="19mm" alt="صورة الطالب"><br>@else<span style="font-size:5pt;color:#647188">صورة الطالب</span><br>@endif
                @if($student->qr_image)<img src="{{ $student->qr_image }}" width="9mm" height="9mm" alt="رمز التحقق">@endif
            </td>
            <td width="55mm" valign="top" style="padding:1mm;font-size:5.5pt;line-height:1.25">
                <div style="height:7mm;font-size:8pt;font-weight:bold">{{ $student->user->name }}</div>
                <div>الرقم الدراسي: <b>{{ $student->student_number }}</b></div>
                <div>البريد: {{ $student->user->email }}</div>
                <div>الصف: {{ $student->classroom?->name ?: '—' }} {{ $student->classroom?->section }}</div>
                <div>السنة: {{ $student->classroom?->academicYear?->name ?: '—' }}</div>
                <div>ولي الأمر: {{ $student->guardians->pluck('user.name')->join('، ') ?: '—' }}</div>
                <div>الهاتف: {{ $student->guardians->pluck('user.phone')->filter()->join('، ') ?: '—' }}</div>
                <div>رقم الهوية: {{ $student->student_number }}</div>
            </td>
            <td width="11mm" align="center" valign="middle" style="padding:0">
                <img src="{{ $pdfSpacer }}" width="1mm" height="44mm" alt="">
            </td>
        </tr>
    </tbody>
</table>
