@extends('admin.layout')
@section('title', 'المنشورات')
@section('subtitle', 'تابع ما يضيفه المعلمون، وابحث حسب الصف أو المعلم أو المادة.')

@section('content')
<link rel="stylesheet" href="{{ asset('css/admin-publications.css') }}?v=20261024-1">
@php
    $filters = $publication['filters'];
    $options = $publication['options'];
    $refreshQuery = array_filter($filters, fn ($value) => $value !== null && $value !== '');
@endphp
<section class="ap-monitor" data-publication-monitor data-refresh-url="{{ route('admin.publications.refresh', $refreshQuery) }}" aria-label="متابعة المنشورات">
    <div class="ap-monitor-bar">
        <div class="ap-live" role="status" aria-live="polite"><span class="ap-live-dot" aria-hidden="true"></span><span data-publication-connection>متابعة مباشرة</span></div>
        <div class="ap-updated">آخر تحقق: <time data-publication-updated>الآن</time></div>
    </div>

    <form class="ap-filters" action="{{ route('admin.publications.index') }}" method="get" data-publication-filters role="search" aria-label="البحث في المنشورات">
        <input type="hidden" name="view" value="{{ $filters['view'] ?? 'classes' }}">
        <div class="ap-search-row">
            <label class="ap-search" for="publication-search">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 4.4 4.4"/></svg>
                <input type="search" id="publication-search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ابحث بعنوان المنشور أو محتواه…" aria-label="البحث بعنوان المنشور أو محتواه" autocomplete="off">
            </label>
            <button class="ap-button ap-button-primary" type="submit">بحث وتصفية</button>
        </div>
        <div class="ap-filter-grid">
            <div class="ap-field"><label for="publication-classroom">الصف</label><select name="classroom_id" id="publication-classroom"><option value="">كل الصفوف</option>@foreach($options['classrooms'] ?? $publication['classrooms'] as $classroom)<option value="{{ $classroom['id'] }}" @selected((string) ($filters['classroom_id'] ?? '') === (string) $classroom['id'])>{{ $classroom['label'] }}</option>@endforeach</select></div>
            <div class="ap-field"><label for="publication-teacher">المعلم</label><select name="teacher_id" id="publication-teacher"><option value="">كل المعلمين</option>@foreach($options['teachers'] as $teacher)<option value="{{ $teacher['id'] }}" @selected((string) ($filters['teacher_id'] ?? '') === (string) $teacher['id'])>{{ $teacher['name'] }}</option>@endforeach</select></div>
            <div class="ap-field"><label for="publication-subject">المادة</label><select name="subject_id" id="publication-subject"><option value="">كل المواد</option>@foreach($options['subjects'] as $subject)<option value="{{ $subject['id'] }}" @selected((string) ($filters['subject_id'] ?? '') === (string) $subject['id'])>{{ $subject['name'] }}</option>@endforeach</select></div>
            <div class="ap-field"><label for="publication-type">نوع المنشور</label><select name="type" id="publication-type"><option value="">كل الأنواع</option>@foreach($options['types'] as $type)<option value="{{ $type['value'] }}" @selected(($filters['type'] ?? '') === $type['value'])>{{ $type['label'] }}</option>@endforeach</select></div>
        </div>
        <details class="ap-more-filters" @if((!empty($filters['status']) && $filters['status'] !== 'published') || !empty($filters['from']) || !empty($filters['to'])) open @endif>
            <summary>الحالة والفترة الزمنية <span aria-hidden="true">⌄</span></summary>
            <div class="ap-date-filters">
                <div class="ap-field"><label for="publication-status">حالة المنشور</label><select name="status" id="publication-status">@foreach($options['statuses'] as $status)<option value="{{ $status['value'] }}" @selected(($filters['status'] ?? 'published') === $status['value'])>{{ $status['label'] }}</option>@endforeach</select></div>
                <div class="ap-field"><label for="publication-from">من تاريخ</label><input type="date" name="from" id="publication-from" value="{{ $filters['from'] ?? '' }}"></div>
                <div class="ap-field"><label for="publication-to">إلى تاريخ</label><input type="date" name="to" id="publication-to" value="{{ $filters['to'] ?? '' }}"></div>
            </div>
        </details>
        <div class="ap-filter-bottom"><span>تُحدّث النتائج تلقائيًا مع الاحتفاظ باختياراتك.</span><a href="{{ route('admin.publications.index') }}" class="ap-reset">مسح التصفية</a></div>
    </form>

    <section data-publication-results data-publication-version="{{ $publication['fingerprint'] }}" data-fingerprint="{{ $publication['fingerprint'] }}" aria-label="نتائج المنشورات">
        @include('admin.publications._results', ['publication' => $publication])
    </section>
</section>
@endsection
@push('scripts')
<script type="module" src="{{ asset('js/admin-publications.js') }}?v=20261024-1"></script>
@endpush
