@extends('teacher.layout')
@section('title', 'التقارير')
@section('content')
<link rel="stylesheet" href="{{ asset('css/teacher-reports.css') }}?v=20261028-4">
<section class="trp-page trp-simple" aria-label="تقارير المعلم">
    @if(!$ready)<div class="trp-note">التقارير غير متاحة حاليًا. تواصل مع الإدارة لإكمال التهيئة.</div>@endif
    <div class="trp-intro"><h2>اختر الصف</h2></div>
    <div class="trp-class-grid">
        @forelse($dashboard['classrooms'] as $room)
            <a class="trp-class-card" @if($ready) href="{{ route('teacher.reports.create', ['classroom_id' => $room['id']]) }}" @else aria-disabled="true" tabindex="-1" @endif>
                <h3>{{ $room['label'] }}</h3>
                <p>{{ collect($room['subjects'])->pluck('name')->implode('، ') }}</p>
                <span class="trp-class-open">فتح التقرير ←</span>
            </a>
        @empty
            <div class="trp-empty"><h3>لا توجد صفوف مسندة إليك</h3><p>تظهر الصفوف هنا بعد ربطها بحساب المعلم.</p></div>
        @endforelse
    </div>
    <section class="trp-saved" aria-labelledby="saved-reports-title">
        <div class="trp-intro"><h2 id="saved-reports-title">التقارير المحفوظة</h2></div>
        <div class="trp-saved-list">
            @forelse($dashboard['reports'] as $saved)
                @php($roomLabel = collect($dashboard['classrooms'])->firstWhere('id', data_get($saved, 'classroom_id'))['label'] ?? '')
                <article class="trp-saved-item">
                    <div><strong>{{ data_get($saved, 'title') }}</strong><p>{{ $roomLabel }}@if(data_get($saved, 'updated_at')) · آخر تعديل <time>{{ \Carbon\CarbonImmutable::parse(data_get($saved, 'updated_at'))->timezone(\App\Services\LessonPublicationTime::timezone())->format('Y/m/d H:i') }}</time>@endif</p></div>
                    <div class="trp-saved-actions"><a class="trp-button trp-button-outline" href="{{ route('teacher.reports.show', data_get($saved, 'id')) }}">فتح التقرير</a><a class="trp-button trp-button-soft" href="{{ route('teacher.reports.print', ['report' => data_get($saved, 'id'), 'format' => 'cards']) }}" target="_blank" rel="noopener">طباعة</a></div>
                </article>
            @empty
                <div class="trp-empty"><h3>لم تحفظ تقريرًا بعد</h3><p>اختر صفًا، وحدد الفترة، ثم احفظ التقرير بعد مراجعته.</p></div>
            @endforelse
        </div>
        @if($dashboard['reports']->hasPages())<div class="trp-pagination">{{ $dashboard['reports']->links() }}</div>@endif
    </section>
</section>
@endsection
