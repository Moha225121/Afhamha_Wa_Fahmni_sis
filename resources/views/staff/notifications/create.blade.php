@extends('staff.layout')
@section('title','إرسال إشعار لولي الأمر')
@section('content')
<div class="panel"><form method="post" action="{{ route('finance.parent-notifications.store') }}">@csrf
<label>الطالب<select name="student_id" required><option value="">اختر الطالب</option>@foreach($students as $student)<option value="{{ $student->id }}">{{ $student->user->name }} — {{ $student->classroom?->name }}</option>@endforeach</select></label>
<label>ولي الأمر<select name="guardian_id" required><option value="">اختر ولي الأمر المرتبط بالطالب</option>@foreach($students as $student)@foreach($student->guardians as $guardian)<option value="{{ $guardian->id }}">{{ $guardian->user->name }} ({{ $student->user->name }})</option>@endforeach @endforeach</select></label>
<label>العنوان<input name="title" required maxlength="255"></label><label>الرسالة<textarea name="message" required maxlength="5000"></textarea></label><button class="btn" type="submit">إرسال</button></form></div>
@endsection
