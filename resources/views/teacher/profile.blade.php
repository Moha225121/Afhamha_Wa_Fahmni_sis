@extends('teacher.layout') @section('title','الملف الشخصي') @section('content')
<form class="form-card" method="post" action="{{ route('teacher.profile.update') }}">
@csrf @method('put')
<div class="form-grid">
<label>الاسم<input name="name" required value="{{ old('name', auth()->user()->name) }}"></label>
<label>الهاتف<input name="phone" value="{{ old('phone', auth()->user()->phone) }}"></label>
<label class="wide">البريد الإلكتروني<input value="{{ auth()->user()->email }}" disabled></label>
<label class="wide">التخصص<input value="{{ $teacher->specialization }}" disabled></label>
</div>
<div class="form-actions"><button class="btn primary">حفظ</button></div>
</form>
@endsection
