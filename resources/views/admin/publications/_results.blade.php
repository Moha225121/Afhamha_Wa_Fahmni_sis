@php
    $filters = $publication['filters'];
    $totals = $publication['totals'];
    $activities = $publication['activities'];
    $selectedClassroom = $publication['selected_classroom'] ?? collect($publication['classrooms'])->first(fn ($classroom) => (string) $classroom['id'] === (string) ($filters['classroom_id'] ?? ''));
    $view = $filters['view'] ?? 'classes';
    $query = array_filter($filters, fn ($value) => $value !== null && $value !== '');
    unset($query['page']);
    $dateText = fn ($value) => $value ? \Carbon\CarbonImmutable::parse($value)->timezone('Africa/Tripoli')->format('Y/m/d · H:i') : 'غير محدد';
    $typeLabels = ['lesson' => 'الدروس', 'assignment' => 'الواجبات', 'grade' => 'الدرجات', 'exam' => 'الاختبارات'];
@endphp
<div class="ap-summary" aria-label="أعداد المنشورات المطابقة">
    <div class="ap-summary-total"><span>المنشورات المطابقة</span><strong>{{ number_format($totals['total']) }}</strong></div>
    @foreach($typeLabels as $kind => $label)
        <div class="ap-summary-item ap-kind-{{ $kind }}"><span class="ap-kind-mark" aria-hidden="true"></span><span>{{ $label }}</span><strong>{{ number_format($totals[$kind]) }}</strong></div>
    @endforeach
</div>

<div class="ap-results-heading">
    <nav class="ap-view-switch" aria-label="طريقة عرض المنشورات">
        <a href="{{ route('admin.publications.index', array_merge($query, ['view' => 'classes', 'classroom_id' => null])) }}" @class(['ap-view-option', 'is-active' => $view === 'classes' && !$selectedClassroom]) @if($view === 'classes' && !$selectedClassroom) aria-current="page" @endif>حسب الصفوف</a>
        <a href="{{ route('admin.publications.index', array_merge($query, ['view' => 'feed'])) }}" @class(['ap-view-option', 'is-active' => $view === 'feed' || $selectedClassroom]) @if($view === 'feed' || $selectedClassroom) aria-current="page" @endif>كل المنشورات</a>
    </nav>
    <p class="ap-results-count">{{ $selectedClassroom ? $selectedClassroom['label'] : 'كل الصفوف' }} <span>· {{ number_format($totals['total']) }} منشور</span></p>
</div>

@if($selectedClassroom)
    <div class="ap-selected-class">
        <div><span>منشورات الصف</span><h2>{{ $selectedClassroom['label'] }}</h2></div>
        <a class="ap-button ap-button-soft" href="{{ route('admin.publications.index', array_merge($query, ['classroom_id' => null, 'view' => 'classes'])) }}">العودة إلى الصفوف <span aria-hidden="true">←</span></a>
    </div>
@endif

@if($view === 'classes' && !$selectedClassroom)
    <div class="ap-section-label"><h2>اختر صفًا</h2><p>افتح الصف للاطلاع على منشوراته وتفاصيلها.</p></div>
    <div class="ap-class-grid">
        @forelse($publication['classrooms'] as $classroom)
            <a class="ap-class-card" href="{{ route('admin.publications.index', array_merge($query, ['classroom_id' => $classroom['id'], 'view' => 'feed'])) }}">
                <div class="ap-class-card-heading"><span class="ap-class-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 4.5h16v12H4zM8 20h8M12 16.5V20M7 8h10M7 11h6"/></svg></span><span class="ap-class-count">{{ number_format($classroom['counts']['total']) }} منشور</span></div>
                <h3>{{ $classroom['label'] }}</h3>
                <div class="ap-class-breakdown">@foreach($typeLabels as $kind => $label)<span class="ap-kind-{{ $kind }}"><i class="ap-kind-mark" aria-hidden="true"></i>{{ $label }} <b>{{ $classroom['counts'][$kind] }}</b></span>@endforeach</div>
                <div class="ap-class-footer"><span>{{ $classroom['latest_at'] ? 'آخر نشاط: '.$dateText($classroom['latest_at']) : 'لا توجد منشورات بعد' }}</span><span class="ap-card-arrow" aria-hidden="true">←</span></div>
            </a>
        @empty
            <div class="ap-empty"><div class="ap-empty-icon" aria-hidden="true">⌕</div><h3>لا توجد صفوف مطابقة</h3><p>غيّر البحث أو امسح التصفية للاطلاع على جميع الصفوف.</p><a class="ap-button ap-button-primary" href="{{ route('admin.publications.index') }}">عرض جميع الصفوف</a></div>
        @endforelse
    </div>
@else
    <div class="ap-feed" aria-label="قائمة المنشورات">
        @forelse($activities as $item)
            <article class="ap-post ap-kind-{{ $item['type'] }}" data-publication-key="{{ $item['key'] }}">
                <div class="ap-post-top"><span class="ap-type-pill"><span class="ap-kind-mark" aria-hidden="true"></span>{{ $item['type_label'] }}</span><span class="ap-status" data-status="{{ $item['status'] }}">{{ $item['status_label'] }}</span></div>
                <h2><a href="{{ route('admin.publications.show', array_merge($query, ['kind' => $item['type'], 'id' => $item['id']])) }}">{{ $item['title'] }}</a></h2>
                <div class="ap-post-byline"><span class="ap-teacher"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"/><path d="M5 21v-3a7 7 0 0 1 14 0v3"/></svg>{{ $item['teacher']['name'] }}</span>@if($item['subject'])<span class="ap-byline-divider" aria-hidden="true">·</span><span>{{ $item['subject']['name'] }}</span>@endif</div>
                @if($item['summary'])<p class="ap-post-excerpt">{{ $item['summary'] }}</p>@endif
                <div class="ap-audience">@foreach($item['classrooms'] as $classroom)<span>{{ $classroom['label'] }}</span>@endforeach</div>
                <footer class="ap-post-footer"><div class="ap-post-dates"><span>{{ $item['published_label'] ?? 'وقت النشر' }} <time datetime="{{ $item['published_at'] }}">{{ $dateText($item['published_at']) }}</time></span>@if($item['updated_at'] && $item['updated_at'] !== $item['published_at'])<span>آخر تعديل <time datetime="{{ $item['updated_at'] }}">{{ $dateText($item['updated_at']) }}</time></span>@endif</div><a class="ap-detail-link" href="{{ route('admin.publications.show', array_merge($query, ['kind' => $item['type'], 'id' => $item['id']])) }}">عرض التفاصيل <span aria-hidden="true">←</span></a></footer>
            </article>
        @empty
            <div class="ap-empty"><div class="ap-empty-icon" aria-hidden="true">⌕</div><h3>لا توجد منشورات مطابقة</h3><p>جرّب معلمًا أو مادة أخرى، أو وسّع الفترة الزمنية.</p><a class="ap-button ap-button-primary" href="{{ route('admin.publications.index', $selectedClassroom ? ['classroom_id' => $selectedClassroom['id'], 'view' => 'feed'] : ['view' => 'feed']) }}">مسح التصفية</a></div>
        @endforelse
    </div>
    @if($activities->hasPages())<div class="ap-pagination">{{ $activities->links() }}</div>@endif
@endif
