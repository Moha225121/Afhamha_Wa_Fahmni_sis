@extends('staff.layout')
@section('title','روابط أولياء الأمور')
@section('subtitle',$student->user->name)
@section('content')<div class="panel"><form class="staff-form" method="post" action="{{ route('staff.students.guardians.update',$student) }}">@csrf @method('put')<label>أولياء الأمور المرتبطون<select name="guardian_ids[]" multiple size="8">@foreach($guardians as $guardian)<option value="{{ $guardian->id }}" @selected($student->guardians->contains($guardian))>{{ $guardian->user->name }} — {{ $guardian->relationship ?: 'ولي أمر' }}</option>@endforeach</select></label><button class="btn primary">حفظ الروابط</button></form></div>@endsection
