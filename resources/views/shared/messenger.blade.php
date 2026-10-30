@extends($layout)
@section('title', 'الرسائل')
@section('subtitle', '')
@section('content')
<link rel="stylesheet" href="{{ asset('css/portal-messenger.css') }}">
<meta name="messenger-csrf" content="{{ csrf_token() }}">
@include('shared.messenger-content')
<script type="module" src="{{ asset('js/portal-messenger.js') }}"></script>
@endsection
