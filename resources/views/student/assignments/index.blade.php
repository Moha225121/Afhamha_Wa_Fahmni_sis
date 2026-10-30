@extends('student.layout')
@section('title', 'الواجبات')
@section('content')
<section class="page-title"><p>{{ $student->user->name }}</p><h1>الواجبات</h1></section>
<section class="list-section">@forelse($assignments as $assignment) @php($submission = $assignment->submissions->first())
@php($deadline = \App\Support\AssignmentDeadline::for($assignment))
<a class="list-row" href="{{ route('student.assignments.show', $assignment) }}"><div><strong>{{ $assignment->title }}</strong><span>{{ $assignment->subject?->name ?? 'مادة غير محددة' }} · {{ $deadline ? 'آخر موعد '.$deadline->setTimezone(\App\Services\LessonPublicationTime::timezone())->format('Y-m-d H:i') : 'بدون موعد نهائي' }}</span></div><b>{{ $submission ? 'تم التسليم' : ($deadline && now()->gte($deadline) ? 'مغلق · لم يسلّم' : 'متاح · لم يسلّم') }}</b></a>
@empty <p class="muted-line">لا توجد واجبات حاليًا.</p> @endforelse</section>{{ $assignments->links() }}
@endsection
