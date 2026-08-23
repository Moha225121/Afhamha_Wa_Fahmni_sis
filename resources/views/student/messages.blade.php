@extends('student.layout')

@section('title', 'رسائل الطالب')

@section('content')
    <section class="page-title">
        <p>إعلانات المدرسة</p>
        <h1>الرسائل</h1>
    </section>

    <section class="messages-list">
        @forelse($announcements as $announcement)
            <article class="message-card">
                <span>{{ $announcement->published_at?->format('Y-m-d') ?? $announcement->created_at?->format('Y-m-d') }}</span>
                <h2>{{ $announcement->title }}</h2>
                <p>{{ $announcement->content }}</p>
            </article>
        @empty
            <div class="empty-state">
                <h2>لا توجد رسائل</h2>
                <p>تظهر هنا الإعلانات المنشورة للطلاب أو الصف.</p>
            </div>
        @endforelse
    </section>
@endsection
