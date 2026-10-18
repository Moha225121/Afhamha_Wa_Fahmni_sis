@extends('student.layout')
@section('title', 'ملف الدرس غير متاح')
@section('content')
    <section class="empty-state" role="alert">
        <h1>ملف الدرس غير متاح حاليًا</h1>
        <p>تعذّر العثور على الملف أو قراءته. تواصل مع المدرسة لتحديث المرفق.</p>
        <a class="primary-link" href="{{ route('student.lessons.show', [$subject, $lesson]) }}">العودة إلى الدرس</a>
    </section>
@endsection
