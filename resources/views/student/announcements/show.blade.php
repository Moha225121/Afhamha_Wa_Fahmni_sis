@extends('student.layout')
@section('title', $announcement->title)
@section('content')
<a class="student-notification-back" href="{{ route('student.notifications') }}"><span aria-hidden="true">→</span> العودة إلى الإشعارات</a>
<article class="student-announcement">
    <div class="student-announcement-meta"><span class="student-notification-icon" aria-hidden="true">@include('student.partials.notification-icon')</span><div><strong>إدارة المدرسة</strong><time datetime="{{ ($announcement->published_at ?? $announcement->created_at)->toIso8601String() }}">{{ ($announcement->published_at ?? $announcement->created_at)->locale('ar')->translatedFormat('j F Y · H:i') }}</time></div><span class="student-announcement-tag">إعلان مدرسي</span></div>
    <h1>{{ $announcement->title }}</h1>
    <div class="student-announcement-content">{{ $announcement->content }}</div>
    <footer><span aria-hidden="true">✓</span> تمت قراءة الإعلان</footer>
</article>
@endsection
