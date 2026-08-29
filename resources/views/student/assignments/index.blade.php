@extends('student.layout')
@section('title', 'الواجبات')
@section('content')
<section class="page-title"><p>{{ $student->user->name }}</p><h1>الواجبات</h1></section>
<section class="list-section">@forelse($assignments as $assignment) @php($submission = $assignment->submissions->first())
<a class="list-row" href="{{ route('student.assignments.show', $assignment) }}"><div><strong>{{ $assignment->title }}</strong><span>{{ $assignment->subject->name }} · آخر موعد {{ $assignment->due_at->format('Y-m-d H:i') }}</span></div><b>{{ $submission ? 'تم التسليم' : ($assignment->due_at->isPast() ? 'مغلق · لم يسلّم' : 'متاح · لم يسلّم') }}</b></a>
@empty <p class="muted-line">لا توجد واجبات حاليًا.</p> @endforelse</section>{{ $assignments->links() }}
@endsection
