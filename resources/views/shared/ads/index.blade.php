@extends($audience.'.layout')
@section('title', 'إعلانات المدرسة')
@section('content')
<section class="page-title">
    <p>الأخبار والتحديثات الموجهة إلى حسابك</p>
    <h1>إعلانات المدرسة</h1>
</section>
<section class="messages-list section-gap">
    @forelse($announcements as $announcement)
        <article class="message-card">
            <span>{{ $announcement->published_at?->format('Y-m-d H:i') ?? $announcement->created_at?->format('Y-m-d H:i') }}</span>
            <h2>{{ $announcement->title }}</h2>
            <div class="announcement-content">{!! nl2br(e($announcement->content)) !!}</div>
        </article>
    @empty
        <section class="empty-state">
            <h2>لا توجد إعلانات حاليًا</h2>
            <p>ستظهر هنا الإعلانات المنشورة الموجهة إلى حسابك أو صفك.</p>
        </section>
    @endforelse
</section>
@endsection
