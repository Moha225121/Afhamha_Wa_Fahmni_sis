@extends('teacher.layout')
@section('title', 'تقرير الصف')
@section('content')
<link rel="stylesheet" href="{{ asset('css/teacher-reports.css') }}?v=20261028-4">
@php
    $periodLabels = ['weekly' => 'أسبوعي', 'monthly' => 'شهري', 'midterm' => 'نصفي', 'final' => 'نهائي', 'overall' => 'شامل'];
    $ratingLabels = ['attention' => 'يرجى الاهتمام', 'good' => 'جيد', 'very_good' => 'جيد جدًا', 'excellent' => 'ممتاز'];
    $categoryLabels = ['weekly' => 'أسبوعي', 'monthly' => 'شهري', 'midterm' => 'نصفي', 'final' => 'نهائي', 'other' => 'تقييمات أخرى'];
    $config = $builder['config'];
    $reportId = $builder['report']['id'];
    $reportUrl = $reportId ? route('teacher.reports.show', $reportId) : '';
    $effectiveCategories = $config['categories'] ?? ($config['report_type'] === 'overall' ? [] : [$config['report_type']]);
    $reportStudents = collect($builder['entries'])->map(fn ($entry) => ['id' => $entry['student_id'], 'name' => $entry['student_name']])->unique('id')->values();
    $firstStudent = $reportStudents->first();
@endphp
<section class="trp-page trp-simple" data-teacher-reports data-preview-url="{{ route('teacher.reports.preview') }}" data-sources-url="{{ route('teacher.reports.sources') }}" data-store-url="{{ route('teacher.reports.store') }}" data-update-url="{{ $reportId ? route('teacher.reports.update', $reportId) : '' }}" data-print-url="{{ $reportId ? route('teacher.reports.print', $reportId) : '' }}" data-recipients-url="{{ $reportUrl ? $reportUrl.'/recipients' : '' }}" data-send-preview-url="{{ $reportUrl ? $reportUrl.'/send-preview' : '' }}" data-send-url="{{ $reportUrl ? $reportUrl.'/send' : '' }}">
    <form hidden data-trp-security>@csrf</form>
    <script type="application/json" data-trp-data>@json($builder, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
    <header class="trp-class-heading"><div><h1>تقرير {{ $builder['classroom']['label'] }}</h1><span data-trp-context-state>{{ $reportId ? 'تقرير محفوظ' : 'تقرير جديد' }}</span></div><a href="{{ route('teacher.reports.index') }}">تغيير الصف ←</a></header>
    @if(!$builder['ready'])
        <p class="trp-note">التقارير غير متاحة حاليًا. تواصل مع الإدارة لإكمال التهيئة.</p>
    @else
    <nav class="trp-period-tabs" aria-label="فترة التقرير">@foreach($periodLabels as $period => $label)<button type="button" class="trp-period-tab {{ $config['report_type'] === $period ? 'is-active' : '' }}" data-trp-period="{{ $period }}" aria-pressed="{{ $config['report_type'] === $period ? 'true' : 'false' }}">{{ $label }}</button>@endforeach</nav>
    <details class="trp-settings" data-trp-settings>
        <summary>إعدادات إضافية</summary>
        <form data-trp-config>
            <div class="trp-field-grid">
                <label class="trp-field"><span>عنوان التقرير</span><input type="text" maxlength="255" required data-trp-config-field="title" value="{{ $builder['report']['title'] }}"></label>
                <label class="trp-field"><span>من تاريخ</span><input type="date" data-trp-config-field="from" value="{{ $config['from'] }}"></label>
                <label class="trp-field"><span>إلى تاريخ</span><input type="date" data-trp-config-field="to" value="{{ $config['to'] }}"></label>
                <div class="trp-field trp-field-wide"><span>المواد</span><div class="trp-checks">@foreach($builder['catalog']['subjects'] as $subject)<label class="trp-check"><input type="checkbox" data-trp-subject value="{{ $subject['id'] }}" @checked(in_array($subject['id'], $config['subject_ids']))>{{ $subject['name'] }}</label>@endforeach</div></div>
                <div class="trp-field trp-field-wide"><span>أنواع التقييمات</span><div class="trp-checks">@foreach($categoryLabels as $category => $label)<label class="trp-check"><input type="checkbox" data-trp-category value="{{ $category }}" @checked(in_array($category, $effectiveCategories))>{{ $label }}</label>@endforeach</div><small>دون تحديد = جميع الأنواع.</small></div>
                <label class="trp-field trp-field-wide"><span>احتساب الدرجة</span><select data-trp-config-field="aggregation"><option value="mean" @selected($config['aggregation'] === 'mean')>متوسط نسب التقييمات</option><option value="points" @selected($config['aggregation'] === 'points')>مجموع الدرجات من المجموع الكلي</option></select></label>
            </div>
            <div class="trp-thresholds"><label class="trp-field"><span>ممتاز: من %</span><input type="number" min="0" max="100" step="0.01" data-trp-threshold="excellent" value="{{ $config['thresholds']['excellent'] }}"></label><label class="trp-field"><span>جيد جدًا: من %</span><input type="number" min="0" max="100" step="0.01" data-trp-threshold="very_good" value="{{ $config['thresholds']['very_good'] }}"></label><label class="trp-field"><span>جيد: من %</span><input type="number" min="0" max="100" step="0.01" data-trp-threshold="good" value="{{ $config['thresholds']['good'] }}"></label></div>
            <div class="trp-settings-actions"><button class="trp-button trp-button-outline" type="submit" data-trp-apply>تطبيق الإعدادات</button><button type="button" class="trp-button trp-button-text" data-trp-refresh>تحديث من سجل الدرجات</button></div>
        </form>
        <div data-trp-warnings>@foreach($builder['warnings'] as $warning)<p class="trp-note">{{ $warning }}</p>@endforeach</div>
        <section class="trp-source-section" data-trp-source-panel aria-label="تنظيم التقييمات">
            <h3>التقييمات المشمولة <small data-trp-source-count>{{ $builder['counts']['assessments'] }} تقييم</small></h3>
            <div class="trp-source-tools"><button type="button" class="trp-button trp-button-text" data-trp-select-sources>تحديد المطابق</button><button type="button" class="trp-button trp-button-text" data-trp-clear-sources>إلغاء التحديد</button><button type="button" class="trp-button trp-button-outline" data-trp-save-metadata>حفظ تنظيم التقييمات</button></div>
            <div class="trp-source-list" data-trp-sources>
                @foreach($builder['catalog']['sources'] as $source)
                    <article class="trp-source {{ $source['needs_mapping'] ? 'is-unclassified' : '' }}" data-trp-source-card="{{ $source['key'] }}">
                        <input type="checkbox" data-trp-source value="{{ $source['key'] }}" @checked(in_array($source['key'], $builder['selected_sources'])) aria-label="تضمين {{ $source['title'] }}">
                        <div><div class="trp-source-title">{{ $source['title'] }}</div><div class="trp-source-meta"><span>{{ $source['subject_name'] ?: 'حدد المادة' }}</span><span>{{ $source['assessment_date'] ?: 'التاريخ غير محدد' }}</span></div></div>
                        <span class="trp-source-count">{{ count($source['marks']) }} درجة</span>
                        <div class="trp-source-mapping">
                            <label class="trp-field"><span>المادة</span>@if($source['metadata_editable']['subject'])<select data-trp-map="subject_id"><option value="">حدد المادة</option>@foreach($builder['catalog']['subjects'] as $subject)<option value="{{ $subject['id'] }}" @selected((string) $source['subject_id'] === (string) $subject['id'])>{{ $subject['name'] }}</option>@endforeach</select>@else<input type="hidden" data-trp-map="subject_id" value="{{ $source['subject_id'] }}"><span>{{ $source['subject_name'] }}</span>@endif</label>
                            <label class="trp-field"><span>نوع التقييم</span><select data-trp-map="assessment_type" @disabled(!$source['metadata_editable']['type'])><option value="">غير محدد</option>@foreach($categoryLabels as $category => $label)<option value="{{ $category }}" @selected($source['assessment_type'] === $category)>{{ $label }}</option>@endforeach</select></label>
                            <label class="trp-field"><span>تاريخ التقييم</span><input type="date" data-trp-map="assessment_date" value="{{ $source['assessment_date'] }}" @disabled(!$source['metadata_editable']['date'])></label>
                        </div>
                    </article>
                @endforeach
            </div>
            <div class="trp-settings-actions"><button type="button" class="trp-button trp-button-outline" data-trp-apply-sources>تطبيق التقييمات المختارة</button></div>
            <div data-trp-metadata-status role="status" aria-live="polite"></div>
        </section>
    </details>
    <p class="trp-section-hint" data-trp-period-note @if($builder['counts']['assessments'] > 0) hidden @endif>لا توجد درجات مرتبطة بهذه الفترة. يمكنك تعبئة الخانات يدويًا أو مراجعة الإعدادات الإضافية.</p>
    <div class="trp-edit-toolbar"><label class="trp-search"><span aria-hidden="true">⌕</span><input type="search" placeholder="ابحث عن طالب أو مادة…" aria-label="البحث في طلاب التقرير" data-trp-search></label><span class="trp-table-hint">يمكنك تعديل الخانات مباشرة.</span></div>
    <div class="trp-save-bar"><div class="trp-save-state" data-trp-save-state role="status" aria-live="polite">{{ $reportId ? 'محفوظ' : 'تقرير جديد' }}</div><div class="trp-save-bar-actions"><button type="button" class="trp-button trp-button-outline" data-trp-print-now>طباعة</button><button type="button" class="trp-button trp-button-outline" data-trp-send-open>إرسال التقرير</button><button type="button" class="trp-button trp-button-primary" data-trp-save>حفظ</button></div></div>
    <div class="trp-error" data-trp-error role="alert" hidden></div>
    @include('teacher.reports._grid')
    <details class="trp-preview-panel trp-preview-details" data-trp-preview-panel><summary>معاينة بطاقة الطالب</summary><div class="trp-preview-controls"><label class="trp-field"><span>الطالب</span><select data-trp-student-preview data-native-select>@foreach($reportStudents as $student)<option value="{{ $student['id'] }}">{{ $student['name'] }}</option>@endforeach</select></label><div class="trp-saved-actions"><a class="trp-button trp-button-text" data-trp-print-student @if($reportId && $firstStudent) href="{{ route('teacher.reports.print', ['report' => $reportId, 'format' => 'cards', 'student_id' => $firstStudent['id']]) }}" @endif>طباعة هذه البطاقة</a><a class="trp-button trp-button-text" data-trp-print-cards @if($reportId) href="{{ route('teacher.reports.print', ['report' => $reportId, 'format' => 'cards']) }}" @endif>طباعة بطاقات الصف</a></div></div><div data-trp-card-container>@include('teacher.reports._card', ['student' => $firstStudent ?? ['id' => 0, 'name' => ''], 'hideCard' => !$firstStudent])<div class="trp-empty" data-trp-card-empty @if($firstStudent) hidden @endif><p>لا يوجد طلاب لعرض البطاقة.</p></div></div></details>
    <dialog class="trp-send-dialog" data-trp-send-dialog aria-labelledby="trp-send-title">
        <div class="trp-dialog-header"><h2 id="trp-send-title">إرسال التقرير</h2><button type="button" class="trp-dialog-close" data-trp-send-close aria-label="إغلاق">×</button></div>
        <fieldset class="trp-audience"><legend>إلى من؟</legend>@if(config('nasbia.external_teacher_reports'))<label><input type="checkbox" value="student" data-trp-audience> الطالب</label><label><input type="checkbox" value="parent" data-trp-audience> ولي الأمر</label>@endif<label><input type="checkbox" value="admin" data-trp-audience> الإدارة</label></fieldset>
        <fieldset class="trp-send-scope"><legend>الطلاب المشمولون</legend><label><input type="radio" name="trp-send-scope" value="all" checked data-trp-send-scope> الصف كاملًا</label><label><input type="radio" name="trp-send-scope" value="selected" data-trp-send-scope> اختيار طلاب</label></fieldset>
        <section class="trp-send-students" data-trp-send-students hidden><label class="trp-search"><span aria-hidden="true">⌕</span><input type="search" data-trp-send-search placeholder="ابحث عن طالب…" aria-label="البحث عن طلاب لإرسال التقرير"></label><div class="trp-send-student-tools"><button type="button" class="trp-button-text" data-trp-send-select-all>تحديد الكل</button><button type="button" class="trp-button-text" data-trp-send-clear>إلغاء التحديد</button><span data-trp-send-selected-count></span></div><div class="trp-send-student-list" data-trp-send-student-list></div><p class="trp-section-hint" data-trp-send-search-empty hidden>لا يوجد طالب مطابق.</p></section>
        <div class="trp-send-summary" data-trp-send-summary role="status" aria-live="polite">اختر من يستلم التقرير.</div><p class="trp-section-hint" data-trp-send-unlinked hidden></p><div class="trp-error" data-trp-send-error role="alert" hidden></div>
        <div class="trp-dialog-actions"><button type="button" class="trp-button trp-button-soft" data-trp-send-close>إلغاء</button><button type="button" class="trp-button trp-button-primary" data-trp-send-confirm disabled>إرسال الآن</button></div>
    </dialog>
    @endif
    <noscript><p class="trp-note">فعّل JavaScript لتعديل التقرير.</p></noscript>
</section>
@endsection
@section('scripts')<script type="module" src="{{ asset('js/teacher-reports.js') }}?v=20261028-4"></script>@endsection
