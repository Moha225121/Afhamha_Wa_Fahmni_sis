@php
    $cardSubject = $resource->subject_name ?: $resource->subject?->name ?: 'موارد متنوعة';
    $cardGrades = collect($resource->grade_levels ?? [])->map(fn ($grade) => $grades[(int) $grade] ?? 'الصف '.$grade);
    $teacherResource = $resource->audience === 'teachers' || in_array($resource->book_type, ['teacher_guide', 'assessment']);
    $fileExtension = strtoupper(pathinfo($resource->original_name ?: $resource->file_path, PATHINFO_EXTENSION));
    $fileBytes = (int) ($resource->file_size ?? 0);
@endphp
<article class="lib-book">
    <div class="lib-book-cover">
        @include('library.subject-icon', ['subjectName' => $cardSubject])
        <span class="lib-file-format" dir="ltr">{{ $fileExtension ?: 'PDF' }}</span>
        <span class="lib-cover-lines" aria-hidden="true"></span>
        @if($teacherResource)<span class="lib-teacher-badge">للمعلمين فقط</span>@endif
    </div>
    <div class="lib-book-body">
        <p class="lib-book-subject">{{ $cardSubject }}</p>
        <h3><a href="{{ route($routePrefix.'.read', $resource) }}">{{ $resource->title }}</a></h3>
        <div class="lib-badges" aria-label="تفاصيل الكتاب">
            @forelse($cardGrades->take(2) as $gradeLabel)
                <span class="lib-badge lib-badge--grade">{{ $gradeLabel }}</span>
            @empty
                <span class="lib-badge lib-badge--grade">{{ $resource->education_stage ?: $resource->classroom?->name ?: 'جميع الصفوف' }}</span>
            @endforelse
            @if($cardGrades->count() > 2)<span class="lib-badge" title="{{ $cardGrades->join('، ') }}">+{{ $cardGrades->count() - 2 }} صفوف</span>@endif
            <span class="lib-badge">{{ $bookTypes[$resource->book_type] ?? $resource->category ?: 'مورد تعليمي' }}</span>
            @if($resource->term)<span class="lib-badge">{{ $resource->term }}</span>@endif
            @if($resource->track)<span class="lib-badge">{{ $resource->track }}</span>@endif
        </div>
        <div class="lib-file-meta">
            @if($resource->page_count)<span>{{ number_format($resource->page_count) }} صفحة</span>@endif
            @if($fileBytes > 0)<span dir="ltr">{{ $fileBytes >= 1048576 ? number_format($fileBytes / 1048576, 1).' MB' : max(1, (int) ceil($fileBytes / 1024)).' KB' }}</span>@endif
            @if($resource->status !== 'active')<span class="lib-status-muted">غير منشور</span>@endif
        </div>
        <div class="lib-book-actions">
            <a class="lib-button lib-button--primary" href="{{ route($routePrefix.'.read', $resource) }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v15M3 4h5a4 4 0 0 1 4 2 4 4 0 0 1 4-2h5v14h-5a4 4 0 0 0-4 2 4 4 0 0 0-4-2H3V4Z"/></svg>قراءة
            </a>
            <a class="lib-button lib-button--secondary" href="{{ route($routePrefix.'.download', $resource) }}" aria-label="تنزيل {{ $resource->title }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/></svg>تنزيل
            </a>
        </div>
        @if(auth()->user()->isAdmin())
            <form class="lib-delete-form" method="post" action="{{ route('admin.library.destroy', $resource->id) }}">
                @csrf @method('delete')
                <button type="submit" data-confirm="سيُحذف هذا الملف من المكتبة نهائيًا. هل تريد المتابعة؟" aria-label="حذف {{ $resource->title }}">حذف الملف</button>
            </form>
        @endif
    </div>
</article>
