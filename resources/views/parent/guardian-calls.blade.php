@extends('parent.layout')
@section('title', 'استدعاءات ولي الأمر')
@section('content')
<section class="page-title"><p>تنبيهات المدرسة</p><h1>استدعاءات ولي الأمر</h1></section>
@if($children->isNotEmpty())
    @include('parent.partials.child-switcher')
    <section class="followup-list" aria-label="استدعاءات الطالب">
        @forelse($calls as $call)
            <article class="followup-card {{ $unreadIds->contains($call->id) ? 'is-unread' : '' }}">
                <div class="followup-labels"><span class="followup-call-label">استدعاء ولي الأمر</span><b>{{ $call->statusLabel() }}</b>@if($unreadIds->contains($call->id))<b class="followup-new">جديد</b>@endif</div>
                <h2><a href="{{ route('parent.guardian-calls.show', $call) }}">{{ $call->parentReason() }}</a></h2>
                <p>{{ \Illuminate\Support\Str::limit($call->parentDetails() ?: 'يرجى مراجعة المدرسة والتواصل مع الإدارة لمتابعة هذا الاستدعاء.', 200) }}</p>
                @if($call->meeting_date)<p><strong>موعد المقابلة:</strong> {{ $call->meeting_date->format('Y-m-d H:i') }}</p>@endif
                <time datetime="{{ $call->created_at->toIso8601String() }}">{{ $call->created_at->format('Y-m-d H:i') }}</time>
                <a class="followup-action" href="{{ route('parent.guardian-calls.show', $call) }}">عرض تفاصيل الاستدعاء <span aria-hidden="true">←</span></a>
            </article>
        @empty
            <section class="empty-state"><h2>لا توجد استدعاءات.</h2></section>
        @endforelse
    </section>
    {{ $calls->links() }}
@else
    <section class="empty-state"><h2>لا يوجد أبناء مرتبطون</h2><p>تظهر الاستدعاءات بعد ربط الطالب بحساب ولي الأمر.</p></section>
@endif
@endsection
