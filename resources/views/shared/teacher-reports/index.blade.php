@extends($layout)
@section('title', 'تقارير المعلمين')
@section('subtitle', 'التقارير المرسلة إليك من المعلمين')
@section('content')
<link rel="stylesheet" href="{{ asset('css/teacher-reports.css') }}?v=20261028-2">
<section class="trp-page">
    @if(auth()->user()->isParent())
        @include('parent.partials.child-switcher', ['action' => route('received-reports.index')])
    @endif
    @if(!auth()->user()->isAdmin())<div class="trp-intro"><div><h1>تقارير المعلمين</h1><p>{{ $selectedStudent ? 'تقارير '.$selectedStudent->user->name : 'تقاريرك المرسلة من المعلمين' }}</p></div></div>@endif
    <div class="trp-saved-list">
        @forelse($deliveries as $delivery)
            @php($snapshot = $delivery->snapshot)
            <article class="trp-saved-item">
                <div><strong>{{ $snapshot['report']['title'] ?? 'تقرير المعلم' }}</strong><p>{{ $snapshot['teacher_name'] ?? '' }} · {{ $snapshot['classroom']['label'] ?? '' }}</p><p>{{ $delivery->created_at?->timezone(\App\Services\LessonPublicationTime::timezone())->format('Y-m-d H:i') }}</p></div>
                <a class="trp-button trp-button-primary" href="{{ route('received-reports.show', $delivery->id) }}">عرض التقرير</a>
            </article>
        @empty
            <div class="trp-empty"><h3>لا توجد تقارير حتى الآن</h3><p>ستظهر هنا التقارير التي يرسلها المعلم إليك.</p></div>
        @endforelse
    </div>
    @if($deliveries->hasPages())<div class="trp-pagination">{{ $deliveries->withQueryString()->links() }}</div>@endif
</section>
@endsection
