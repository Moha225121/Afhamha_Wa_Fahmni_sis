@extends('admin.layout')
@section('title', 'المكتبة الرقمية')
@section('subtitle', 'كتب المناهج والموارد التعليمية، مرتبة وسهلة الوصول')
@section('actions')<a class="lib-button lib-button--primary" href="#library-upload" data-library-open-upload>+ إضافة ملف</a>@endsection
@section('content')
    <link rel="stylesheet" href="{{ asset('css/library.css') }}?v=20260930-1">
    @include('library.upload')
    @include('library.catalog')
    <script src="{{ asset('js/library-upload.js') }}?v=20260930-1" defer></script>
@endsection
