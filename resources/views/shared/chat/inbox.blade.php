@extends($layout)
@section('title', 'رسائل أولياء الأمور')
@include('shared.chat.assets')
@section('content')
<section class="school-chat"><div class="chat-page-heading"><div><h2>المحادثات</h2><p>افتح محادثة لقراءة رسائل ولي الأمر والرد عليه.</p></div></div>@include('shared.chat.contacts')</section>
@endsection
