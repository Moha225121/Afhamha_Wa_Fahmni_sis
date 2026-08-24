@extends('student.layout')
@section('title','الحضور')
@section('content')
<section class="page-title"><p>{{ $student->user->name }}</p><h1>سجل الحضور</h1></section><section class="list-section">@forelse($records as $record)<div class="list-row"><strong>{{ $record->date }}</strong><b>{{ ['present'=>'حاضر','absent'=>'غائب','late'=>'متأخر','excused'=>'بعذر'][$record->status] ?? $record->status }}</b></div>@empty<p class="muted-line">لا توجد سجلات حضور.</p>@endforelse</section>{{ $records->links() }}
@endsection
