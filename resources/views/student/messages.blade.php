@extends('student.layout')

@section('title', 'رسائل الطالب')

@section('content')
    <section class="page-title">
        <p>إعلانات المدرسة</p>
        <h1>الرسائل</h1>
    </section>

    @include('shared.messenger', ['layout' => 'student.layout', 'inbox' => app(\App\Services\PortalMessengerService::class)->inbox(request())])
@endsection
