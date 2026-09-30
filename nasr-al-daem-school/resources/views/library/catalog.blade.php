@php
    $libraryStaff = auth()->user()->isAdmin() || auth()->user()->isTeacher();
    $filterKeys = ['q', 'subject', 'grade', 'book_type', 'audience', 'track', 'term', 'category', 'subject_id', 'stage'];
    $activeFilters = collect($filters)->only($filterKeys)->filter(fn ($value) => filled($value));
    $libraryQuery = $activeFilters->all();
@endphp
<div class="library-catalog">
    <section class="lib-hero" aria-labelledby="library-heading">
        <div class="lib-hero-copy">
            <p class="lib-eyebrow"><span aria-hidden="true">✦</span> مساحة للمعرفة</p>
            @if($routePrefix === 'student.library')<h1 id="library-heading">المكتبة الرقمية</h1>@else<h2 id="library-heading">كل كتبك في مكان واحد</h2>@endif
            <p>تصفّح المناهج الليبية حسب الصف والمادة. اقرأ مباشرة، أو نزّل كتبك لتبقى معك.</p>
            @if($routePrefix === 'teacher.library')<span class="lib-hero-note">كتب المنهج، وأدلة المعلم، ومواد التقييم</span>@endif
            @if($routePrefix === 'student.library' && $student?->classroom)<span class="lib-hero-note">كتب {{ $student->classroom->name }} تظهر تلقائيًا</span>@endif
        </div>
        <dl class="lib-stats">
            <div><dt>ملف متاح</dt><dd>{{ number_format($stats['total'] ?? 0) }}</dd></div>
            <div><dt>مادة دراسية</dt><dd>{{ number_format($stats['subjects'] ?? 0) }}</dd></div>
            @if($libraryStaff && ($stats['teacher_only'] ?? 0) > 0)<div><dt>مورد للمعلم</dt><dd>{{ number_format($stats['teacher_only']) }}</dd></div>@endif
        </dl>
    </section>

    <form class="lib-filter-panel" method="get" action="{{ route($routePrefix.'.index') }}" role="search" aria-label="البحث في المكتبة">
        <label class="lib-search-label" for="library-search">ابحث عن كتابك</label>
        <div class="lib-search-row">
            <div class="lib-search-field"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg><input id="library-search" name="q" type="search" maxlength="200" value="{{ $filters['q'] ?? '' }}" placeholder="اسم الكتاب، المادة، أو كلمات من المحتوى…" autocomplete="off"></div>
            <button class="lib-button lib-button--primary" type="submit">بحث وتصفية</button>
        </div>
        <div class="lib-filter-grid">
            @if($routePrefix !== 'student.library')<label>الصف الدراسي<select name="grade" data-native-select><option value="">جميع الصفوف</option>@foreach($grades as $number => $label)<option value="{{ $number }}" @selected((string) ($filters['grade'] ?? '') === (string) $number)>{{ $label }}</option>@endforeach</select></label>@endif
            <label>المادة<select name="subject" data-native-select><option value="">جميع المواد</option>@foreach($catalogSubjects as $subjectItem)<option value="{{ $subjectItem->name }}" @selected(($filters['subject'] ?? '') === $subjectItem->name)>{{ $subjectItem->name }}</option>@endforeach</select></label>
            <label>نوع الكتاب<select name="book_type" data-native-select><option value="">جميع الأنواع</option>@foreach($bookTypes as $value => $label)@if($libraryStaff || !in_array($value, ['teacher_guide', 'assessment']))<option value="{{ $value }}" @selected(($filters['book_type'] ?? '') === $value)>{{ $label }}</option>@endif @endforeach</select></label>
            <label>الفصل / الجزء<select name="term" data-native-select><option value="">كل الفصول والأجزاء</option>@foreach($catalogTerms ?? [] as $termValue)<option value="{{ $termValue }}" @selected(($filters['term'] ?? '') === $termValue)>{{ $termValue }}</option>@endforeach</select></label>
            <label>المسار<select name="track" data-native-select><option value="">جميع المسارات</option>@foreach($catalogTracks ?? [] as $trackValue)<option value="{{ $trackValue }}" @selected(($filters['track'] ?? '') === $trackValue)>{{ $trackValue }}</option>@endforeach</select></label>
            @if($libraryStaff)<label>موجّه إلى<select name="audience" data-native-select><option value="">الطلاب والمعلمين</option><option value="all" @selected(($filters['audience'] ?? '') === 'all')>موارد مشتركة</option><option value="teachers" @selected(($filters['audience'] ?? '') === 'teachers')>المعلمون فقط</option></select></label>@endif
        </div>
        @foreach(['category', 'subject_id', 'stage'] as $legacyFilter)@if(filled($filters[$legacyFilter] ?? null))<input type="hidden" name="{{ $legacyFilter }}" value="{{ $filters[$legacyFilter] }}">@endif @endforeach
        @if($activeFilters->isNotEmpty())
            <div class="lib-active-filters">
                <span>التصفية الحالية:</span>
                @foreach($activeFilters as $filterKey => $filterValue)
                    @php
                        $filterLabel = match ($filterKey) {
                            'grade' => $grades[(int) $filterValue] ?? $filterValue,
                            'book_type' => $bookTypes[$filterValue] ?? $filterValue,
                            'audience' => $filterValue === 'teachers' ? 'للمعلمين فقط' : 'موارد مشتركة',
                            'subject_id' => 'مادة مرتبطة بالصف',
                            default => $filterValue,
                        };
                    @endphp
                    <a class="lib-filter-chip" href="{{ route($routePrefix.'.index', $activeFilters->except($filterKey)->all()) }}" aria-label="إزالة تصفية {{ $filterLabel }}">{{ $filterLabel }} <span aria-hidden="true">×</span></a>
                @endforeach
                <a class="lib-clear-link" href="{{ route($routePrefix.'.index') }}">مسح الكل</a>
            </div>
        @endif
    </form>

    @if($catalogSubjects->isNotEmpty())
        <section class="lib-subject-section" aria-labelledby="library-subjects-heading">
            <div class="lib-section-heading"><h2 id="library-subjects-heading">استكشف حسب المادة</h2><span>{{ $catalogSubjects->count() }} مادة</span></div>
            <div class="lib-subject-strip" tabindex="0" aria-label="المواد الدراسية، يمكن التمرير أفقيًا">
                @foreach($catalogSubjects as $subjectItem)
                    <a class="lib-subject-tile {{ ($filters['subject'] ?? '') === $subjectItem->name ? 'is-active' : '' }}" href="{{ route($routePrefix.'.index', array_replace($libraryQuery, ['subject' => $subjectItem->name])) }}" @if(($filters['subject'] ?? '') === $subjectItem->name) aria-current="true" @endif>
                        @include('library.subject-icon', ['subjectName' => $subjectItem->name])
                        <strong>{{ $subjectItem->name }}</strong><span>{{ number_format($subjectItem->count) }} ملف</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <section class="lib-results" aria-labelledby="library-results-heading">
        <div class="lib-section-heading"><div><h2 id="library-results-heading">{{ $activeFilters->isNotEmpty() ? 'نتائج البحث' : 'تصفّح الكتب' }}</h2><p>{{ number_format($resources->total()) }} ملف{{ $activeFilters->isNotEmpty() ? ' مطابق للتصفية' : ' في مكتبتك' }}</p></div>@if($resources->total() > 0)<span>عرض {{ $resources->firstItem() }}–{{ $resources->lastItem() }}</span>@endif</div>
        <div class="lib-book-grid">
            @forelse($resources as $resource)
                @include('library.card')
            @empty
                <div class="lib-empty">
                    @include('library.subject-icon', ['subjectName' => ''])
                    <h3>{{ $activeFilters->isNotEmpty() ? 'لم نجد كتبًا بهذه المواصفات' : 'المكتبة جاهزة لكتبك' }}</h3>
                    <p>{{ $activeFilters->isNotEmpty() ? 'جرّب اسمًا أقصر، أو اختر مادة أو صفًا آخر.' : (auth()->user()->isAdmin() ? 'أضف أول ملف، وحدد مادته وصفه ليسهل الوصول إليه.' : 'ستظهر هنا الكتب والموارد المتاحة لك عند إضافتها.') }}</p>
                    @if($activeFilters->isNotEmpty())<a class="lib-button lib-button--secondary" href="{{ route($routePrefix.'.index') }}">عرض جميع الكتب</a>@elseif(auth()->user()->isAdmin())<a class="lib-button lib-button--primary" href="#library-upload" data-library-open-upload>إضافة ملف للمكتبة</a>@endif
                </div>
            @endforelse
        </div>
        @if($resources->hasPages())
            <nav class="lib-pagination {{ $routePrefix === 'student.library' ? 'student-pagination' : '' }}" aria-label="صفحات المكتبة">
                @if($resources->onFirstPage())<span class="lib-button lib-button--secondary" aria-disabled="true">السابق</span>@else<a class="lib-button lib-button--secondary" href="{{ $resources->previousPageUrl() }}" rel="prev">السابق</a>@endif
                <span>صفحة <b>{{ $resources->currentPage() }}</b> من {{ $resources->lastPage() }}</span>
                @if($resources->hasMorePages())<a class="lib-button lib-button--secondary" href="{{ $resources->nextPageUrl() }}" rel="next">التالي</a>@else<span class="lib-button lib-button--secondary" aria-disabled="true">التالي</span>@endif
            </nav>
        @endif
    </section>
</div>
