@extends('parent.layout')

@section('title', 'أبنائي')

@section('content')
    <section class="page-title">
        <p>الأبناء المرتبطون</p>
        <h1>أبنائي</h1>
    </section>

    <section class="children-list">
        @forelse($children as $child)
            <a class="child-card" href="{{ route('parent.children.show', $child) }}">
                <span class="avatar">{{ mb_substr($child->user->name, 0, 1) }}</span>
                <div>
                    <strong>{{ $child->user->name }}</strong>
                    <small>{{ $child->student_number }} · {{ $child->classroom?->name ?? 'بدون صف' }}</small>
                </div>
                <i>›</i>
            </a>
            @if(app(\App\Services\ParentStudentSession::class)->canOpen($child))
                <form method="post" action="{{ route('parent.children.student-session', $child) }}" class="section-gap">
                    @csrf
                    <button type="submit" class="btn primary">فتح مساحة الطالب</button>
                </form>
            @endif
        @empty
            <div class="empty-state">
                <h2>لا يوجد أبناء مرتبطون</h2>
                <p>يتم الربط من حساب الإدارة الرئيسي.</p>
            </div>
        @endforelse
    </section>
@endsection
