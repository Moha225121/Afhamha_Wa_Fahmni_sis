@extends('student.layout')

@section('title', 'المكتبة الرقمية')

@section('content')
    <section class="page-title">
        <p>{{ $student->classroom?->stage ?? 'جميع المراحل المتاحة' }}</p>
        <h1>المكتبة الرقمية</h1>
    </section>

    <form class="filter-form" method="get" action="{{ route('student.library.index') }}">
        <label class="wide">البحث
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="ابحث بالعنوان أو التصنيف">
        </label>
        <label>التصنيف
            <select name="category">
                <option value="">الكل</option>
                @foreach($categories as $category)
                    <option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>{{ $category }}</option>
                @endforeach
            </select>
        </label>
        <label>المادة
            <select name="subject_id">
                <option value="">الكل</option>
                @foreach($subjects as $subject)
                    <option value="{{ $subject->id }}" @selected((string) ($filters['subject_id'] ?? '') === (string) $subject->id)>{{ $subject->name }}</option>
                @endforeach
            </select>
        </label>
        <label>المرحلة
            <select name="stage">
                <option value="">الكل</option>
                @foreach($stages as $stage)
                    <option value="{{ $stage }}" @selected(($filters['stage'] ?? '') === $stage)>{{ $stage }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit">تطبيق</button>
        <a href="{{ route('student.library.index') }}">مسح الفلاتر</a>
    </form>

    <section class="resource-grid" aria-label="نتائج المكتبة">
        @forelse($resources as $resource)
            <article class="resource-card">
                <span>{{ $resource->category ?: 'مورد تعليمي' }}</span>
                <h2>{{ $resource->title }}</h2>
                <p>{{ $resource->subject?->name ?: 'مورد عام' }} · {{ $resource->classroom?->stage ?: $resource->subject?->stage ?: 'كل المراحل' }}</p>
                <a href="{{ route('student.library.download', $resource) }}">تنزيل المورد</a>
            </article>
        @empty
            <div class="empty-state">
                <h2>لا توجد نتائج</h2>
                <p>جرّب تغيير البحث أو الفلاتر.</p>
            </div>
        @endforelse
    </section>

    @if($resources->hasPages())
        <nav class="student-pagination" aria-label="صفحات المكتبة">
            @if($resources->onFirstPage())
                <span aria-disabled="true">السابق</span>
            @else
                <a href="{{ $resources->previousPageUrl() }}" rel="prev">السابق</a>
            @endif

            @if($resources->hasMorePages())
                <a href="{{ $resources->nextPageUrl() }}" rel="next">التالي</a>
            @else
                <span aria-disabled="true">التالي</span>
            @endif
        </nav>
    @endif
@endsection
