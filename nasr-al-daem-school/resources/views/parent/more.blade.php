@extends('parent.layout')

@section('title', 'المزيد')

@section('content')
    <section class="page-title">
        <p>{{ $guardian->user->name }}</p>
        <h1>المزيد</h1>
    </section>

    <section class="more-list">
        <a href="{{ route('parent.profile') }}">
            <span>◉</span>
            الملف الشخصي
        </a>
        <a href="{{ route('parent.children.index') }}">
            <span>◱</span>
            أبنائي
        </a>
        <a href="{{ route('parent.messages') }}">
            <span>✉</span>
            الرسائل
        </a>
        <a href="{{ route('parent.attendance') }}">
            <span>✓</span>
            الحضور
        </a>
        <a href="{{ route('parent.assignments') }}">
            <span>▤</span>
            الواجبات
        </a>
        <a href="{{ route('parent.exams') }}">
            <span>◌</span>
            الاختبارات والنتائج
        </a>
        <a href="{{ route('parent.notifications') }}">
            <span>●</span>
            الإشعارات
        </a>
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button type="submit"><span>↶</span> تسجيل الخروج</button>
        </form>
    </section>

    <p class="tech-partner">HEXA Tech</p>
@endsection
