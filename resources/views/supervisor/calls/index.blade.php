@extends('supervisor.layout')
@section('title', 'استدعاءات أولياء الأمور')
@section('content')
<link rel="stylesheet" href="{{ asset('css/supervisor-calls.css') }}?v=20261012-calls">

<section class="supervisor-calls-page">
    <header class="calls-intro">
        <p class="eyebrow">تواصل مع ولي الأمر</p>
        <h2>إرسال استدعاء جديد</h2>
        <p>اختر الطالب، اكتب سبب الاستدعاء، ثم اضغط إرسال. سيصل تنبيه إلى ولي الأمر المرتبط بالطالب.</p>
    </header>

    <section class="calls-compose">
        <div class="calls-compose-head"><span class="calls-step">1</span><div><h2>بيانات الاستدعاء</h2><p>الطالب والسبب هما البيانات المطلوبة.</p></div></div>
        @if($students->isEmpty())
            <div class="empty">لا يوجد طلاب ضمن الفصول المكلف بها.</div>
        @else
            <form method="post" action="{{ route('supervisor.guardian-calls.direct-store') }}" class="calls-form">
                @csrf
                <label><span>الطالب <b>*</b></span><select name="student_id" required><option value="">اختر الطالب</option>@foreach($students as $student)<option value="{{ $student->id }}" @selected((int) old('student_id') === $student->id)>{{ $student->user->name }} — {{ $student->classroom?->name }} {{ $student->classroom?->section }}</option>@endforeach</select></label>
                <label><span>سبب الاستدعاء <b>*</b></span><input name="reason" value="{{ old('reason') }}" required maxlength="255" placeholder="مثال: تكرار الغياب"></label>
                <details class="calls-optional" @if($errors->any()) open @endif><summary>الموعد والتفاصيل الإضافية (اختياري)</summary><div class="calls-optional-grid">
                    <label><span>نوع السبب</span><select name="category">@foreach(['other'=>'أخرى','attendance'=>'الحضور','repeated_absence'=>'تكرار الغياب','repeated_lateness'=>'تكرار التأخير','behavior'=>'السلوك','academic_followup'=>'متابعة أكاديمية','health'=>'صحية','psychological'=>'متابعة نفسية','administrative'=>'إدارية'] as $value=>$label)<option value="{{ $value }}" @selected(old('category', 'other') === $value)>{{ $label }}</option>@endforeach</select></label>
                    <label><span>موعد المقابلة</span><input type="datetime-local" name="meeting_date" value="{{ old('meeting_date') }}"></label>
                    <label><span>تفاصيل داخلية</span><textarea name="details" maxlength="5000" placeholder="يراها موظفو المدرسة فقط">{{ old('details') }}</textarea></label>
                    <label><span>رسالة لولي الأمر</span><textarea name="guardian_visible_details" maxlength="5000" placeholder="هذه الرسالة ستظهر لولي الأمر">{{ old('guardian_visible_details') }}</textarea></label>
                </div></details>
                <div class="calls-submit"><p><b>*</b> بعد الإرسال يظهر الاستدعاء للولي في الإشعارات واستدعاءات ولي الأمر.</p><button class="btn primary" type="submit">إرسال الاستدعاء للولي</button></div>
            </form>
        @endif
    </section>

    <section class="calls-history">
        <div class="calls-history-head"><div><p class="eyebrow">المتابعة</p><h2>الاستدعاءات السابقة</h2></div><span>{{ $calls->total() }} استدعاء</span></div>
        <div class="table-wrap"><table><thead><tr><th>الطالب</th><th>السبب</th><th>الحالة</th><th>الموعد</th></tr></thead><tbody>@forelse($calls as $call)<tr><td>{{ $call->student->user->name }}</td><td>{{ $call->reason }}</td><td><form method="post" action="{{ route('supervisor.guardian-calls.update',$call) }}" class="call-status-form">@csrf @method('patch')<select name="status" aria-label="حالة استدعاء {{ $call->student->user->name }}">@foreach(['pending'=>'قيد الانتظار','notified'=>'تم الإبلاغ','scheduled'=>'تم تحديد موعد','completed'=>'تمت المقابلة','cancelled'=>'ملغي'] as $value=>$label)<option value="{{ $value }}" @selected($call->status===$value)>{{ $label }}</option>@endforeach</select><button type="submit">حفظ</button></form></td><td>{{ $call->meeting_date?->format('Y-m-d H:i') ?: '—' }}</td></tr>@empty<tr><td colspan="4"><div class="empty">لا توجد استدعاءات مسجلة.</div></td></tr>@endforelse</tbody></table></div>
        {{ $calls->links() }}
    </section>
</section>
@endsection
