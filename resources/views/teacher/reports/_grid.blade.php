<div class="trp-grid-wrap" role="region" aria-label="درجات طلاب الصف" tabindex="0">
    <table class="trp-grid">
        <thead><tr><th scope="col">الطالب والمادة</th><th scope="col">الدرجة %</th><th scope="col">التقدير</th><th scope="col">السلوك</th><th scope="col">النشاط</th><th scope="col">ملاحظات</th></tr></thead>
        <tbody data-trp-entries>
            @foreach($builder['entries'] as $entry)
                <tr data-trp-entry="{{ $entry['key'] }}" data-search="{{ $entry['student_name'].' '.$entry['subject_name'] }}">
                    <td data-label="الطالب والمادة"><button type="button" class="trp-button-text trp-student-name" data-trp-preview-student="{{ $entry['student_id'] }}">{{ $entry['student_name'] }}</button><span class="trp-subject-name">{{ $entry['subject_name'] }}</span></td>
                    <td data-label="الدرجة %"><div class="trp-grade-control"><input type="number" min="0" max="100" step="0.01" value="{{ $entry['score'] ?? '' }}" data-trp-entry-field="score" aria-label="درجة {{ $entry['student_name'] }} في {{ $entry['subject_name'] }}"><button type="button" class="trp-reset" data-trp-reset-score title="الرجوع إلى الدرجة التلقائية" aria-label="الرجوع إلى الدرجة التلقائية">↶</button></div><span class="trp-mode {{ $entry['score_mode'] === 'manual' ? 'is-manual' : '' }}" data-trp-score-mode>{{ $entry['score_mode'] === 'manual' ? 'تعديل يدوي' : 'تلقائي من الدرجات المختارة' }}</span><details class="trp-evidence"><summary>مصدر الدرجة ({{ count($entry['evidence']) }})</summary><ul>@foreach($entry['evidence'] as $item)<li>{{ $item['title'] }}: {{ $item['score'] }} / {{ $item['maximum'] }}</li>@endforeach</ul></details></td>
                    <td data-label="التقدير"><select data-trp-entry-field="rating" aria-label="تقدير {{ $entry['student_name'] }} في {{ $entry['subject_name'] }}"><option value="auto" @selected($entry['rating_mode'] === 'auto')>تلقائي{{ $entry['rating'] ? ' — '.$ratingLabels[$entry['rating']] : '' }}</option><option value="blank" @selected($entry['rating_mode'] === 'manual' && !$entry['rating'])>دون تقدير</option>@foreach($ratingLabels as $code => $label)<option value="{{ $code }}" @selected($entry['rating_mode'] === 'manual' && $entry['rating'] === $code)>{{ $label }}</option>@endforeach</select><span class="trp-mode" data-trp-rating-mode>{{ $entry['rating_mode'] === 'manual' ? 'اختيار يدوي' : 'يتبع الدرجة وحدود التقدير' }}</span></td>
                    <td data-label="السلوك"><select data-trp-entry-field="behaviour" aria-label="سلوك {{ $entry['student_name'] }} في {{ $entry['subject_name'] }}"><option value="">لم يُحدد</option><option value="calm" @selected($entry['behaviour'] === 'calm')>هادئ</option><option value="disruptive" @selected($entry['behaviour'] === 'disruptive')>مشاغب</option></select></td>
                    <td data-label="النشاط"><textarea rows="2" maxlength="500" data-trp-entry-field="activity" aria-label="النشاط — {{ $entry['student_name'] }} — {{ $entry['subject_name'] }}" placeholder="أضف النشاط يدويًا…">{{ $entry['activity'] }}</textarea></td>
                    <td data-label="ملاحظات"><textarea rows="2" maxlength="2000" data-trp-entry-field="notes" aria-label="ملاحظات — {{ $entry['student_name'] }} — {{ $entry['subject_name'] }}" placeholder="ملاحظاتك للطالب…">{{ $entry['notes'] }}</textarea></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
<div class="trp-empty" data-trp-no-students @if(count($builder['entries'])) hidden @endif><h3>لا يوجد طلاب لعرضهم</h3><p>تأكد من اختيار مادة ووجود طلاب مسجلين في الصف.</p></div>
<div class="trp-note is-info" data-trp-search-empty hidden>لا توجد أسماء مطابقة للبحث.</div>
