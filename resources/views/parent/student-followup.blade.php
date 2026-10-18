@extends('parent.layout')
@section('title', 'متابعة الطالب')
@section('content')
<section class="page-title"><p>ملاحظات المشرف ومتابعة أبنائك</p><h1>متابعة الطالب</h1></section>
@if($children->isNotEmpty())
    @include('parent.partials.child-switcher')
    <section class="followup-list" aria-label="ملاحظات الطالب">
        @forelse($notes as $note)
            <article class="followup-card {{ $unreadIds->contains($note->id) ? 'is-unread' : '' }}">
                <div class="followup-labels">
                    <span>ملاحظة {{ $note->typeLabel() }}</span>
                    @if($unreadIds->contains($note->id))<b class="followup-new">جديد</b>@endif
                    @if($note->requires_guardian_call || $note->guardianCalls->isNotEmpty())<b class="followup-call-label">استدعاء ولي الأمر</b>@endif
                </div>
                <h2><a href="{{ route('parent.student-followup.show', $note) }}">{{ $note->title }}</a></h2>
                <p>{{ \Illuminate\Support\Str::limit($note->content, 200) }}</p>
                <time datetime="{{ $note->created_at->toIso8601String() }}">{{ $note->created_at->format('Y-m-d H:i') }}</time>
                <a class="followup-action" href="{{ route('parent.student-followup.show', $note) }}">عرض تفاصيل الملاحظة <span aria-hidden="true">←</span></a>
            </article>
        @empty
            <section class="empty-state"><h2>لا توجد ملاحظات متاحة لولي الأمر.</h2></section>
        @endforelse
    </section>
    {{ $notes->links() }}
@else
    <section class="empty-state"><h2>لا يوجد أبناء مرتبطون</h2><p>تظهر ملاحظات الطالب بعد ربطه بحساب ولي الأمر.</p></section>
@endif
@endsection
