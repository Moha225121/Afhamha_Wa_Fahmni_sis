@extends($layout)
@section('title', 'رسائل أولياء الأمور')
@include('shared.chat.assets')
@section('content')
<section class="school-chat"><div class="chat-page-heading"><div><h2>المحادثات</h2><p>افتح محادثة لقراءة رسائل ولي الأمر والرد عليه.</p></div></div>
@if(auth()->user()->isAdmin())
<form class="filters" method="get">
    <input name="q" value="{{ request('q') }}" placeholder="ابحث في جميع محادثاتك" aria-label="البحث في جميع محادثاتك">
    <select name="classroom_id" aria-label="الصف والشعبة"><option value="">كل الصفوف والشعب</option>@foreach($classrooms as $room)<option value="{{ $room->id }}" @selected(request('classroom_id') == $room->id)>{{ $room->name }} {{ $room->section }}</option>@endforeach</select>
    <select name="status" aria-label="حالة المحادثة"><option value="">كل الحالات</option><option value="open" @selected(request('status') === 'open')>مفتوحة</option><option value="closed" @selected(request('status') === 'closed')>مغلقة</option></select>
    <button class="btn secondary">تصفية</button><a class="btn secondary" href="{{ route('admin.conversations.index') }}">إعادة الضبط</a>
</form>
@endif
@include('shared.chat.contacts')
{{ $conversations->links() }}
</section>
@endsection
