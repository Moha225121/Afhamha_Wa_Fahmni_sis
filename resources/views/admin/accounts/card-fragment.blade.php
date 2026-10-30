@php($cardTheme = preg_match('/^#[0-9a-f]{6}$/i', $schoolBranding['theme_color'] ?? '') ? $schoolBranding['theme_color'] : '#008C95')
<article class="student-id-card" aria-label="بطاقة الطالب {{ $student->user->name }}" style="position:relative;width:84mm;height:53mm;min-width:84mm;min-height:53mm;max-width:84mm;max-height:53mm;box-sizing:border-box;overflow:hidden;margin:0;padding:0;border:0.35mm solid #d5dce7;border-radius:3mm;background:#fff;color:{{ $cardTheme }};font-family:dejavusans,Tahoma,Arial,sans-serif;text-align:right;direction:rtl">
    <header style="position:absolute;top:0;right:0;left:0;height:9mm;box-sizing:border-box;padding:0.8mm 2mm;background:{{ $cardTheme }};color:#fff;white-space:nowrap;overflow:hidden">
        <img src="{{ $pdfLogo ?? \App\Support\SchoolBranding::logoUrl() }}" alt="شعار مدرسة النسبية" style="width:7mm;height:7mm;vertical-align:middle;margin-left:1mm;object-fit:contain">
        <span style="font-size:7.2pt;font-weight:bold;color:#fff;vertical-align:middle">{{ $schoolBranding['school_name'] }}</span>
        <span style="float:left;margin-top:1.2mm;font-size:6.5pt;color:#FFFFFF;font-weight:bold">بطاقة الطالب</span>
    </header>

    <div class="student-photo" style="position:absolute;top:12mm;right:2.5mm;width:18mm;height:20mm;box-sizing:border-box;overflow:hidden;border:0.3mm solid #d5dce7;border-radius:1.5mm;background:#f2f4f7;text-align:center;color:#647188;font-size:5.5pt;line-height:19mm">
        @if($student->photo_image)
            <img src="{{ $student->photo_image }}" alt="صورة الطالب" style="display:block;width:100%;height:100%;object-fit:cover">
        @else
            صورة الطالب
        @endif
    </div>

    <section style="position:absolute;top:11mm;right:23mm;left:2.5mm;bottom:2mm;overflow:hidden">
        <div class="student-name" title="{{ $student->user->name }}" style="height:7.2mm;max-height:7.2mm;overflow:hidden;color:{{ $cardTheme }};font-size:8pt;line-height:1.1;font-weight:bold;word-break:break-word">{{ $student->user->name }}</div>
        <div style="height:3.3mm;overflow:hidden;white-space:nowrap;font-size:5.5pt;line-height:1.2">الرقم: <b dir="ltr">{{ $student->student_number }}</b></div>
        <div title="{{ $student->user->email }}" style="height:3.3mm;overflow:hidden;white-space:nowrap;font-size:5.5pt;line-height:1.2">البريد: <b dir="ltr">{{ $student->user->email }}</b></div>
        <div title="{{ trim(($student->classroom?->name ?? '').' '.($student->classroom?->section ?? '')) }}" style="height:3.3mm;overflow:hidden;white-space:nowrap;font-size:5.5pt;line-height:1.2">الصف: {{ $student->classroom?->name ?: '—' }} {{ $student->classroom?->section }}</div>
        <div style="height:3.3mm;overflow:hidden;white-space:nowrap;font-size:5.5pt;line-height:1.2">السنة: {{ $student->classroom?->academicYear?->name ?: '—' }}</div>
        <div title="{{ $student->guardians->pluck('user.name')->join('، ') }}" style="height:3.3mm;overflow:hidden;white-space:nowrap;font-size:5.5pt;line-height:1.2">ولي الأمر: {{ $student->guardians->pluck('user.name')->join('، ') ?: '—' }}</div>
        <div title="{{ $student->guardians->pluck('user.phone')->filter()->join('، ') }}" style="height:3.3mm;overflow:hidden;white-space:nowrap;font-size:5.5pt;line-height:1.2">الهاتف: <b dir="ltr">{{ $student->guardians->pluck('user.phone')->filter()->join('، ') ?: '—' }}</b></div>
        <div style="height:3.3mm;overflow:hidden;white-space:nowrap;font-size:5.5pt;line-height:1.2">الهوية: <b dir="ltr">{{ $student->student_number }}</b></div>
    </section>

    @if($student->qr_image ?? null)
        <img src="{{ $student->qr_image }}" alt="رمز التحقق للطالب" style="position:absolute;left:2.5mm;bottom:2mm;width:9mm;height:9mm">
    @endif
</article>
