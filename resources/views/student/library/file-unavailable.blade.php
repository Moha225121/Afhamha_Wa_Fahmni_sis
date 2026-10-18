@extends('student.layout')
@section('title', 'الكتاب غير متاح')
@section('content')
    <section class="empty-state" role="alert">
        <h1>ملف الكتاب غير متاح حاليًا</h1>
        <p>تعذّر العثور على ملف «{{ $resource->title }}» أو قراءته. تواصل مع المدرسة لتحديث الملف.</p>
        <a class="primary-link" href="{{ route('student.library.index') }}">العودة إلى المكتبة</a>
    </section>
@endsection
