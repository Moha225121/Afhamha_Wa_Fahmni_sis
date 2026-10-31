@extends('parent.layout')
@section('title', $announcement->title)
@section('content')
<a class="parent-notification-back" href="{{ route('parent.notifications') }}"><span aria-hidden="true">→</span> العودة إلى الإشعارات</a>
<article class="parent-announcement">
    <div class="parent-announcement-meta"><span class="parent-notification-icon" aria-hidden="true">@include('parent.partials.notification-icon')</span><div><strong>إدارة المدرسة</strong><time datetime="{{ ($announcement->published_at ?? $announcement->created_at)->toIso8601String() }}">{{ ($announcement->published_at ?? $announcement->created_at)->locale('ar')->translatedFormat('j F Y · H:i') }}</time></div><span class="parent-announcement-tag">إعلان مدرسي</span></div>
    <h1>{{ $announcement->title }}</h1>
    <div class="parent-announcement-content">{{ $announcement->content }}</div>
    <footer><span aria-hidden="true">✓</span> تمت قراءة الإعلان</footer>
</article>
@endsection
