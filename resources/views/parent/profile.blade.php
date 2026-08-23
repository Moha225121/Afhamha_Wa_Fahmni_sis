@extends('parent.layout')

@section('title', 'الملف الشخصي')

@section('content')
    <section class="page-title">
        <p>حساب ولي الأمر</p>
        <h1>الملف الشخصي</h1>
    </section>

    <form method="post" action="{{ route('parent.profile.update') }}" class="profile-form">
        @csrf
        @method('put')
        <label>
            الاسم
            <input name="name" value="{{ old('name', $guardian->user->name) }}" required maxlength="255">
        </label>
        <label>
            رقم الهاتف
            <input name="phone" value="{{ old('phone', $guardian->user->phone) }}" maxlength="30" inputmode="tel">
        </label>
        <label>
            البريد الإلكتروني
            <input value="{{ $guardian->user->email }}" disabled>
        </label>
        <label>
            صلة القرابة
            <input value="{{ $guardian->relationship ?? '-' }}" disabled>
        </label>
        <button type="submit">حفظ</button>
    </form>
@endsection
