@extends('admin.layout')
@section('title','إعدادات الترقيم والحسابات')
@section('content')
<form class="form-card" method="post" action="{{ route('admin.accounts.preview') }}" id="account-patterns">@csrf
<h2>هوية المدرسة</h2><div class="form-grid">
@foreach(['school_name'=>'اسم المدرسة بالعربية','school_english_name'=>'اسم المدرسة بالإنجليزية','school_code'=>'رمز المدرسة / الفرع'] as $key=>$label)
<label>{{ $label }}<input name="{{ $key }}" value="{{ old($key,$settings[$key]) }}" required></label>@endforeach
</div><p class="muted">تغيير اسم المدرسة أو نمط البريد يؤثر على الحسابات الجديدة. لتحديث الحسابات الحالية، فعّل تحديث البريد أدناه وراجع المعاينة قبل الاعتماد.</p>
@foreach(['student'=>'الطلاب','teacher'=>'المعلمون'] as $role=>$label)
<h2>{{ $label }} <small>({{ $counts[$role] }} حسابًا، بما فيها المؤرشفة)</small></h2>
<div class="form-grid">
<label>نمط الرقم<input dir="ltr" name="{{ $role }}_number_pattern" value="{{ old($role.'_number_pattern',$settings[$role.'_number_pattern']) }}" required></label>
<label>بداية التسلسل<input type="number" min="1" max="999999999" name="{{ $role }}_start" value="{{ old($role.'_start',$settings[$role.'_start']) }}" required></label>
<label>عدد الخانات<input type="number" min="1" max="12" name="{{ $role }}_digits" value="{{ old($role.'_digits',$settings[$role.'_digits']) }}" required></label>
<label>إعادة التسلسل كل سنة<select name="{{ $role }}_reset_yearly"><option value="0" @selected(!old($role.'_reset_yearly',$settings[$role.'_reset_yearly']))>لا</option><option value="1" @selected(old($role.'_reset_yearly',$settings[$role.'_reset_yearly']))>نعم</option></select></label>
<label>نمط البريد<input dir="ltr" name="{{ $role }}_email_pattern" value="{{ old($role.'_email_pattern',$settings[$role.'_email_pattern']) }}" required></label>
<label>توليد البريد تلقائيًا<select name="{{ $role }}_auto_email"><option value="1" @selected(old($role.'_auto_email',$settings[$role.'_auto_email']))>مفعّل</option><option value="0" @selected(!old($role.'_auto_email',$settings[$role.'_auto_email']))>معطّل</option></select></label>
</div>
<p>مثال الرقم: <output dir="ltr" data-number="{{ $role }}"></output></p><p>مثال البريد: <output dir="ltr" data-email="{{ $role }}"></output></p>
<label><input type="checkbox" name="renumber_{{ $role }}" value="1" @checked(old('renumber_'.$role))> إعادة ترقيم جميع {{ $label }} (تُنفَّذ تلقائيًا عند تغيير نمط الرقم)</label>
<label><input type="checkbox" name="update_{{ $role }}_emails" value="1" @checked(old('update_'.$role.'_emails'))> تحديث البريد المدرسي لجميع {{ $label }} وفق الأرقام والأنماط الجديدة</label>
@endforeach
<p class="muted" dir="ltr">Numbers: {YEAR} {GRADE} {BRANCH} {CLASS} {SEQ} {0001}<br>Emails: {FIRST_NAME} {LAST_NAME} {ACADEMIC_NUMBER} {TEACHER_NUMBER} {YEAR} {SCHOOL_NAME}</p>
<label>عرض الحالة المالية للطالب<select name="student_finance_visible"><option value="0" @selected(!$settings['student_finance_visible'])>معطّل</option><option value="1" @selected($settings['student_finance_visible'])>مفعّل</option></select></label>
<button class="btn primary">معاينة التأثير قبل الحفظ</button>
</form>
@endsection
@push('scripts')<script>
const form=document.getElementById('account-patterns');
function previewPatterns(){const val=k=>form.elements[k].value,clean=s=>s.toLowerCase().replace(/[^a-z0-9_-]/g,'');for(const role of ['student','teacher']){const n=val(role+'_start'),tokens={YEAR:@json($previewContext['YEAR']),GRADE:'G10',CLASS:'A',BRANCH:val('school_code'),SEQ:n.padStart(Number(val(role+'_digits')),'0')};const number=val(role+'_number_pattern').replace(/\{(YEAR|GRADE|CLASS|BRANCH|SEQ|0+1)\}/g,(m,k)=>/^0+1$/.test(k)?n.padStart(k.length,'0'):tokens[k]);const emailTokens={FIRST_NAME:'mohamed',LAST_NAME:'ahmed',ACADEMIC_NUMBER:clean(number),TEACHER_NUMBER:clean(number),YEAR:tokens.YEAR,SCHOOL_NAME:val('school_english_name').toLowerCase().replace(/[^a-z0-9]/g,'')};form.querySelector('[data-number="'+role+'"]').textContent=number;form.querySelector('[data-email="'+role+'"]').textContent=val(role+'_email_pattern').replace(/\{([A-Z_]+)\}/g,(m,k)=>emailTokens[k]??m).toLowerCase();}}
form.addEventListener('input',previewPatterns);previewPatterns();
</script>@endpush
