@extends('teacher.layout')
@section('title', 'المكتبة الرقمية')
@section('subtitle', 'مراجعك التعليمية وكتب طلابك، في مكان واحد')
@section('content')
    <link rel="stylesheet" href="{{ asset('css/library.css') }}?v=20260930-1">
    @include('library.catalog')
@endsection
