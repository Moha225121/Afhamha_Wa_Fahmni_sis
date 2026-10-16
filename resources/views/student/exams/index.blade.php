@extends('student.layout')
@section('title', 'الاختبارات')
@section('content')
<section class="page-title"><p>{{ $student->user->name }}</p><h1>الاختبارات</h1></section>
@if(session('success')) <div class="notice success" role="status">{{ session('success') }}</div> @endif
@if($errors->any()) <div class="notice error" role="alert">{{ $errors->first() }}</div> @endif
@foreach(['available' => 'متاحة الآن', 'upcoming' => 'قادمة', 'completed' => 'تم تنفيذها', 'past' => 'سابقة أو لم تُنفذ'] as $key => $title)
<section class="list-section"><div class="section-title"><h2>{{ $title }}</h2></div>
@forelse($examGroups[$key] as $exam) @include('student.exams.partials.row', ['exam' => $exam, 'group' => $key]) @empty<p class="muted-line">لا توجد اختبارات في هذه الفئة.</p>@endforelse
</section>
@endforeach
{{ $exams->links() }}
@endsection
