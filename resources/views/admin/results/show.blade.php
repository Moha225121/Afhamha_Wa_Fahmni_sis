@extends('admin.layout')
@section('title',$period->name)
@section('content')
<section class="panel" aria-label="خطوات نشر النتائج">
<p>إنشاء الفترة لا ينشر النتائج. المسار: إدخال أو استيراد الدرجات ← المراجعة ← الاعتماد ← النشر.</p>
@if($period->status === 'draft')
@if($marks->isEmpty())<p role="status">الخطوة التالية: أدخل الدرجات أو استوردها من الاختبارات المصححة المطابقة للسنة والصف والشعبة. لا يمكن إرسال فترة فارغة إلى المراجعة.</p>
@else<p>الخطوة التالية: تحقق من درجات المواد أدناه، ثم أرسل الفترة إلى المراجعة. تبقى النتائج مخفية عن الطالب وولي الأمر.</p>@endif
@elseif($period->status === 'under_review')<p>راجع الدرجات ثم اختر «معتمدة»، أو أعد الفترة إلى المسودة للتصحيح. الاعتماد وحده لا يعرض النتائج قبل النشر.</p>
@elseif($period->status === 'approved')<p>الدرجات معتمدة. اختر «منشورة» للنشر الآن، أو انتظر موعد النشر المحدد؛ لا ينفذ النشر المجدول إلا للفترة المعتمدة وعند تشغيل الجدولة.</p>
@elseif($period->status === 'published')<p>النتائج ظاهرة لأصحابها. للتصحيح أخفِ الفترة ثم أعدها إلى المسودة، وراجعها واعتمدها قبل إعادة النشر.</p>
@else<p>النتائج مخفية. أعدها إلى المسودة للتصحيح أو انشر النسخة المعتمدة مجددًا.</p>@endif
<p>لكل طالب درجة واحدة لكل مادة داخل هذه الفترة ونوع امتحانها. حفظ نفس الطالب والمادة يعدّل الدرجة الموجودة؛ تبقى المواد والفترات الأخرى مستقلة. اختر فترة مستقلة للتقييمات ذات نوع امتحان مختلف.</p>
</section>
<div class="panel"><p>الفصل: {{ $period->term }} — نوع الامتحان: {{ $period->exam_type }}</p><p>الحالة: <b>{{ $statuses[$period->status] }}</b> — الطلاب في النطاق: {{ $students->count() }} — الدرجات المسجلة: {{ $marks->count() }}</p><p>تاريخ النشر المخطط: {{ $period->scheduled_at??'غير محدد' }}</p><form method="post" action="{{ route('admin.results.status',$period->id) }}">@csrf @method('patch')<label>الحالة التالية<select name="status">@foreach(['draft'=>['under_review'],'under_review'=>['draft','approved'],'approved'=>['draft','published'],'published'=>['hidden'],'hidden'=>['draft','published']][$period->status] as $status)<option value="{{ $status }}">{{ $statuses[$status] }}</option>@endforeach</select></label><button class="btn primary">اعتماد تغيير الحالة</button></form></div>
@if($period->status==='draft')<form class="form-card" method="post" action="{{ route('admin.results.mark',$period->id) }}">@csrf<h2>إضافة أو تعديل درجة مادة</h2><div class="form-grid"><label>الطالب<select name="student_id" required>@foreach($students as $student)<option value="{{ $student->id }}">{{ $student->user->name }} — {{ $student->student_number }}</option>@endforeach</select></label><label>المادة<select name="subject_id">@foreach($subjects as $subject)<option value="{{ $subject->id }}">{{ $subject->name }}</option>@endforeach</select></label><label>الدرجة<input type="number" name="score" min="0" step="0.01" required></label><label>الدرجة الكلية<input type="number" name="maximum_score" min="0.01" step="0.01" value="100" required></label><label>ملاحظات<textarea name="notes"></textarea></label></div><button class="btn primary">حفظ الدرجة</button></form>
<details class="panel"><summary>استيراد كشف المعلم الحالي</summary><p>حدد المادة المسندة للمعلم. تُستورد درجات الكشف من 100 إلى المسودة ولا تظهر قبل النشر.</p><form method="post" action="{{ route('admin.results.import-sheet',$period->id) }}">@csrf<div class="form-grid"><label>الكشف<select name="sheet_id" required>@foreach($sheets as $sheet)<option value="{{ $sheet->id }}">{{ $sheet->teacher }} — {{ $sheet->classroom }} {{ $sheet->section }}</option>@endforeach</select></label><label>المادة<select name="subject_id">@foreach($subjects as $subject)<option value="{{ $subject->id }}">{{ $subject->name }}</option>@endforeach</select></label></div><button class="btn secondary">استيراد إلى المسودة</button></form></details>
<details class="panel"><summary>استيراد درجات الاختبارات</summary><p>اختر اختبارًا واحدًا لكل مادة. تحل الدرجات المستوردة محل درجة المادة الحالية للطالب، ثم تخضع للمراجعة والاعتماد.</p><form method="post" action="{{ route('admin.results.import',$period->id) }}">@csrf @forelse($exams as $exam)<label><input type="checkbox" name="exam_ids[]" value="{{ $exam->id }}">{{ $exam->subject }} — {{ $exam->title }}</label>@empty<p>لا توجد اختبارات مطابقة للنطاق.</p>@endforelse<button class="btn secondary">استيراد إلى المسودة</button></form></details>@endif
<div class="table-wrap"><table><thead><tr><th>الطالب</th><th>المادة</th><th>الدرجة</th><th>الكلية</th><th>ملاحظات</th><th>الإجراءات</th></tr></thead><tbody>
@forelse($marks as $mark)
<tr><td>{{ $mark->student }}</td><td>{{ $mark->subject }}</td><td>{{ $mark->score }}</td><td>{{ $mark->maximum_score }}</td><td style="white-space: pre-wrap">{{ $mark->notes }}</td><td>
@if($period->status === 'draft')
<details><summary>تعديل الدرجة</summary><form method="post" action="{{ route('admin.results.mark',$period->id) }}">@csrf
<input type="hidden" name="student_id" value="{{ $mark->student_id }}"><input type="hidden" name="subject_id" value="{{ $mark->subject_id }}">
<label>الدرجة<input type="number" name="score" value="{{ $mark->score }}" min="0" step="0.01" required></label>
<label>الدرجة الكلية<input type="number" name="maximum_score" value="{{ $mark->maximum_score }}" min="0.01" step="0.01" required></label>
<label>ملاحظات<textarea name="notes">{{ $mark->notes }}</textarea></label><button class="btn secondary">حفظ التعديل</button>
</form></details>
<form method="post" action="{{ route('admin.results.mark.destroy',[$period->id,$mark->id]) }}" onsubmit="return confirm('حذف درجة المسودة فقط؟ تبقى محاولة الطالب ودرجة الاختبار الأصلية محفوظتين.')">@csrf @method('delete')<button class="btn secondary">حذف درجة المسودة</button></form>
@else<span>التعديل متاح في المسودة فقط</span>@endif
</td></tr>
@empty<tr><td colspan="6">لا توجد درجات لهذه الفترة.</td></tr>@endforelse
</tbody></table></div>
@endsection
