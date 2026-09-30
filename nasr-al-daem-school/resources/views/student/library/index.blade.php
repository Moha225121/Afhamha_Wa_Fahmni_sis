@extends('student.layout')
@section('title', 'المكتبة الرقمية')
@section('content')
    <link rel="stylesheet" href="{{ asset('css/library.css') }}?v=20260930-1">
    @include('library.catalog')
@endsection
