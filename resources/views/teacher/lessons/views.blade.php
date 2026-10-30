@extends('teacher.layout')
@section('title', 'مشاهدات الدرس')
@section('subtitle', 'معرفة الطلاب الذين فتحوا الدرس')
@section('actions')
    <a class="btn secondary" href="{{ route('teacher.lessons.index') }}">العودة إلى الدروس</a>
@endsection
@section('content')
<link rel="stylesheet" href="{{ asset('css/lesson-views.css') }}?v=20261013-1">
<section class="lesson-readership" aria-label="ملخص مشاهدات الدرس">
    <div class="lesson-readership__details">
        <p class="eyebrow">{{ $lesson->subject?->name }}</p>
        <h2>{{ $lesson->title }}</h2>
        <p class="muted">{{ $lesson->classrooms->isNotEmpty() ? $lesson->classrooms->map(fn ($classroom) => trim($classroom->name.' '.$classroom->section))->join('، ') : ($lesson->classroom ? trim($lesson->classroom->name.' '.$lesson->classroom->section) : 'جميع الصفوف المتاح لها الدرس') }}</p>
    </div>
    <div class="lesson-readership__count">
        <strong>{{ $summary['viewed'] }}</strong>
        <span>طلاب فتحوا الدرس</span>
    </div>
</section>
<p class="lesson-readership-note">تُسجّل المشاهدات منذ تفعيل المتابعة، ويُحتسب كل طالب مرة واحدة عند فتح صفحة الدرس. فتح الدرس لا يؤكد إكمال قراءته أو مشاهدة ملفاته.</p>
<form class="filters lesson-view-filters" method="get" action="{{ route('teacher.lessons.views', $lesson) }}">
    <label><span class="label-head">البحث عن طالب</span><input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ابحث باسم الطالب" maxlength="100"></label>
    <button class="btn primary" type="submit">بحث</button>
    @if(! empty($filters['q']))<a class="btn secondary" href="{{ route('teacher.lessons.views', $lesson) }}">عرض الجميع</a>@endif
</form>
<div class="table-wrap lesson-view-table">
    <table>
        <thead><tr><th scope="col">اسم الطالب</th><th scope="col">الصف الحالي</th><th scope="col">أول فتح للدرس</th><th scope="col">آخر فتح للدرس</th></tr></thead>
        <tbody>
        @forelse($viewers as $viewer)
            @php($student = $viewer->student)
            <tr>
                <td data-label="اسم الطالب"><strong>{{ $student?->user?->name ?? 'طالب غير متاح' }}</strong></td>
                <td data-label="الصف الحالي">{{ $student?->classroom ? trim($student->classroom->name.' '.$student->classroom->section) : 'غير مسند' }}</td>
                <td data-label="أول فتح للدرس"><time datetime="{{ $viewer->first_viewed_at->toIso8601String() }}"><bdi>{{ $viewer->first_viewed_at->copy()->timezone($publicationTimezone)->format('Y-m-d H:i') }}</bdi></time></td>
                <td data-label="آخر فتح للدرس"><time datetime="{{ $viewer->last_viewed_at->toIso8601String() }}"><bdi>{{ $viewer->last_viewed_at->copy()->timezone($publicationTimezone)->format('Y-m-d H:i') }}</bdi></time></td>
            </tr>
        @empty
            <tr><td colspan="4" class="lesson-view-empty">{{ ! empty($filters['q']) ? 'لا توجد نتائج مطابقة لهذا البحث.' : 'لم يُسجّل فتح هذا الدرس من أي طالب حتى الآن.' }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
{{ $viewers->links() }}
@endsection
