@extends('parent.layout')

@section('title', 'التواصل')

@section('content')
    <section class="page-title">
        <p>تواصل ومتابعة</p>
        <h1>الرسائل والإعلانات</h1>
    </section>

    @if($children->isNotEmpty())
        @include('parent.partials.child-switcher')

        <section class="list-section">
            <div class="section-title">
                <h2>المحادثات</h2>
                <a href="{{ route('parent.conversations.index', ['student' => $selectedStudent?->id]) }}">فتح</a>
            </div>
            <p class="muted-line">راسل إدارة المدرسة أو معلمي {{ $selectedStudent?->user->name }} المسندين إلى صفه.</p>
        </section>
    @endif

    <section class="messages-list section-gap">
        <div class="section-title section-title-plain">
            <h2>إعلانات المدرسة</h2>
        </div>
        @forelse($announcements as $announcement)
            <article class="message-card">
                <span>{{ $announcement->published_at?->format('Y-m-d') ?? $announcement->created_at?->format('Y-m-d') }}</span>
                <h2>{{ $announcement->title }}</h2>
                <p>{{ $announcement->content }}</p>
            </article>
        @empty
            <div class="empty-state">
                <h2>لا توجد رسائل</h2>
                <p>تظهر هنا الإعلانات المنشورة لولي الأمر أو صفوف الأبناء.</p>
            </div>
        @endforelse
    </section>
@endsection
