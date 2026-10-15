@extends('student.layout')
@section('title', 'المكتبة الرقمية')
@section('content')
    <link rel="stylesheet" href="{{ asset('css/library.css') }}?v=20261001-mobile1">
    @include('library.catalog')
    <script src="{{ asset('js/library-catalog.js') }}?v=20261001-filters1" defer></script>
@endsection
