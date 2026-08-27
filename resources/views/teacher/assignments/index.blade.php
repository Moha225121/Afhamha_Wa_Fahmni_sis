@extends('teacher.layout')
@section('title', 'تسليمات الواجبات')
@section('content')
<section class="page-title"><p>{{ $teacher->user->name }}</p><h1>تسليمات الواجبات</h1></section>
<section class="list-section">@forelse($assignments as $assignment)<a class="list-row" href="{{ route('teacher.assignments.show', $assignment) }}"><div><strong>{{ $assignment->title }}</strong><span>{{ $assignment->subject->name }} · {{ $assignment->classroom->name }}</span></div><b>{{ $assignment->submissions_count }} تسليم</b></a>@empty<p class="muted-line">لا توجد واجبات مرتبطة بحسابك.</p>@endforelse</section>{{ $assignments->links() }}
@endsection
