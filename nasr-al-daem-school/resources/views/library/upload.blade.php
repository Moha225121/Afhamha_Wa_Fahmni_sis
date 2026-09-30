<details class="lib-upload" id="library-upload" @if($errors->any()) open @endif>
    <summary><span class="lib-upload-plus" aria-hidden="true">+</span><span><strong>إضافة ملف للمكتبة</strong><small>رتّب بيانات الكتاب مرة واحدة، ليجده الجميع بسهولة.</small></span><span class="lib-upload-chevron" aria-hidden="true">⌄</span></summary>
    <form class="lib-upload-form" method="post" enctype="multipart/form-data" action="{{ route('admin.library.store') }}" data-library-upload data-start-url="{{ route('admin.library.uploads.start') }}" data-chunk-url="{{ route('admin.library.uploads.chunk', ['upload' => '__UPLOAD__']) }}" data-cancel-url="{{ route('admin.library.uploads.cancel', ['upload' => '__UPLOAD__']) }}">
        @csrf
        <div class="lib-upload-file">
            <label for="library-file">اختر الكتاب أو المورد التعليمي</label>
            <input id="library-file" type="file" name="file" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.mp4" aria-describedby="library-file-hint">
            <p id="library-file-hint">تُرفع الكتب الكبيرة على أجزاء. @if((int) config('library.max_upload_mb', 0) > 0)الحد الأقصى {{ number_format((int) config('library.max_upload_mb')) }} MB لكل ملف.@elseلا يوجد حد لحجم الكتاب داخل المكتبة.@endif أبقِ هذه الصفحة مفتوحة حتى اكتمال الرفع.</p>
        </div>
        <div class="lib-upload-fields">
            <label class="lib-field-wide">عنوان مختصر <span class="lib-optional">اختياري</span><input name="title" maxlength="255" value="{{ old('title') }}" placeholder="مثال: الرياضيات — الصف الخامس"><small>اتركه فارغًا لإنشاء عنوان عربي من اسم الملف.</small></label>
            <label>المادة الدراسية<input name="subject_name" maxlength="100" list="library-subject-names" value="{{ old('subject_name') }}" placeholder="مثال: الرياضيات"><datalist id="library-subject-names">@foreach($catalogSubjects as $subjectItem)<option value="{{ $subjectItem->name }}">@endforeach</datalist></label>
            <label>نوع الكتاب<select name="book_type" data-native-select data-library-book-type><option value="" @selected(!old('book_type'))>تلقائي من اسم الملف</option>@foreach($bookTypes as $value => $label)<option value="{{ $value }}" @selected(old('book_type') === $value)>{{ $label }}</option>@endforeach</select></label>
            <label>الفصل / الجزء<input name="term" maxlength="100" value="{{ old('term') }}" list="library-terms" placeholder="مثال: الجزء الأول"><datalist id="library-terms">@foreach($catalogTerms ?? [] as $termValue)<option value="{{ $termValue }}">@endforeach</datalist></label>
            <label>المسار<select name="track" data-native-select><option value="">جميع المسارات</option>@foreach(collect($catalogTracks ?? [])->merge(['علمي', 'أدبي'])->unique() as $trackValue)<option value="{{ $trackValue }}" @selected(old('track') === $trackValue)>{{ $trackValue }}</option>@endforeach</select></label>
            <label>المرحلة<select name="education_stage" data-native-select><option value="">حسب الصفوف المحددة</option><option value="التعليم الأساسي" @selected(old('education_stage') === 'التعليم الأساسي')>التعليم الأساسي</option><option value="التعليم الثانوي" @selected(old('education_stage') === 'التعليم الثانوي')>التعليم الثانوي</option></select></label>
            <label>صلاحية العرض<select name="audience" data-native-select data-library-audience><option value="all" @selected(old('audience', 'all') === 'all')>الطلاب والمعلمون</option><option value="teachers" @selected(old('audience') === 'teachers')>المعلمون فقط</option></select><small data-library-audience-hint>أدلة المعلم ومواد التقييم مخصصة للمعلمين فقط.</small></label>
        </div>
        <fieldset class="lib-grade-options"><legend>الصفوف المستهدفة <span class="lib-optional">يمكن اختيار أكثر من صف</span></legend><div>@foreach($grades as $number => $label)<label><input type="checkbox" name="grade_levels[]" value="{{ $number }}" @checked(in_array((string) $number, array_map('strval', old('grade_levels', []))))><span>{{ $label }}</span></label>@endforeach</div></fieldset>
        <details class="lib-scope-options"><summary>خيارات الربط بالصفوف والمواد داخل المدرسة</summary><div class="lib-upload-fields">
            <label>مادة مرتبطة<select name="subject_id" data-native-select><option value="">بدون ربط بمادة محددة</option>@foreach($subjects as $schoolSubject)<option value="{{ $schoolSubject->id }}" @selected((string) old('subject_id') === (string) $schoolSubject->id)>{{ $schoolSubject->name }}</option>@endforeach</select></label>
            <label>صف وشعبة محددان<select name="classroom_id" data-native-select><option value="">بدون ربط بشعبة محددة</option>@foreach($classrooms as $schoolClassroom)<option value="{{ $schoolClassroom->id }}" @selected((string) old('classroom_id') === (string) $schoolClassroom->id)>{{ $schoolClassroom->name }} — {{ $schoolClassroom->section }}</option>@endforeach</select></label>
            <label>تصنيف إضافي<input name="category" maxlength="100" value="{{ old('category') }}" placeholder="اختياري"></label>
        </div><input type="hidden" name="is_public" value="0"><label class="lib-scope-check"><input type="checkbox" name="is_public" value="1" @checked((string) old('is_public', '1') === '1')><span>مورد عام في مكتبة المدرسة<small>يبقى الوصول للمستخدمين المسجّلين حسب صلاحية العرض. عند إلغاء الخيار، اربط المورد بمادة أو صف.</small></span></label></details>
        <div class="lib-upload-progress" data-library-progress hidden role="status" aria-live="polite">
            <div><strong data-library-progress-label>جارٍ تجهيز الرفع…</strong><span data-library-progress-value dir="ltr">0%</span></div>
            <progress max="100" value="0" aria-label="تقدم رفع الملف"></progress>
            <p data-library-progress-detail></p>
        </div>
        <p class="lib-upload-error" data-library-upload-error role="alert" hidden></p>
        <div class="lib-upload-footer"><p>يمكن للطلاب قراءة الكتب وتنزيلها وفق صلاحياتهم.</p><div><button class="lib-button lib-button--secondary" type="button" data-library-cancel hidden>إلغاء الرفع</button><button class="lib-button lib-button--primary" type="submit" data-library-submit>رفع الملف وحفظه</button></div></div>
        <noscript><p class="lib-upload-error">رفع الكتب الكبيرة يحتاج إلى تفعيل JavaScript. الرفع المباشر يخضع لحدود الخادم.</p></noscript>
    </form>
</details>
