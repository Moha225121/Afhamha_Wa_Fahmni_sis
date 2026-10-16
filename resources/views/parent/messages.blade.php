@extends('parent.layout')

@section('title', 'الرسائل')
@include('shared.chat.assets')

@section('content')
<div class="school-chat chat-inbox">
    <div class="chat-page-heading"><div><h1>الرسائل</h1><p>تواصل مع المدرسة ومعلمي أبنائك</p></div>@if($children->isNotEmpty())<a class="chat-new" href="{{ route('parent.conversations.create', ['student' => $selectedStudent?->id]) }}"><x-portal-icon name="plus"/>محادثة جديدة</a>@endif</div>
    <nav class="chat-tabs" aria-label="نوع الرسائل">
        <a class="{{ $activeTab === 'chats' ? 'is-active' : '' }}" href="{{ route('parent.messages', request()->only('student')) }}" @if($activeTab === 'chats') aria-current="page" @endif><x-portal-icon name="chat"/>المحادثات</a>
        <a class="{{ $activeTab === 'announcements' ? 'is-active' : '' }}" href="{{ route('parent.messages', ['tab' => 'announcements'] + request()->only('student')) }}" @if($activeTab === 'announcements') aria-current="page" @endif><x-portal-icon name="announcement"/>إعلانات المدرسة @if($announcements->total())<b>{{ $announcements->total() }}</b>@endif</a>
    </nav>
    <section aria-label="المحادثات" @if($activeTab !== 'chats') hidden @endif>
        @if($children->count() > 1)
            <form method="get" class="chat-child-filter"><label for="chat-student">عرض محادثات</label><select id="chat-student" name="student" onchange="this.form.submit()" data-native-select><option value="">كل الأبناء</option>@foreach($children as $child)<option value="{{ $child->id }}" @selected((int) request('student') === $child->id)>{{ $child->user->name }}</option>@endforeach</select><noscript><button>عرض</button></noscript></form>
        @endif
        @include('shared.chat.contacts')
    </section>
    <section class="chat-announcements" aria-label="إعلانات المدرسة" @if($activeTab !== 'announcements') hidden @endif>
        @forelse($announcements as $announcement)
            <article class="chat-announcement">
                <span class="chat-announcement-source"><x-portal-icon name="announcement"/>إدارة المدرسة</span>
                <h2>{{ $announcement->title }}</h2>
                <p>{{ \Illuminate\Support\Str::limit($announcement->content, 220) }}</p>
                <footer><time>{{ $announcement->published_at?->format('Y-m-d') ?? $announcement->created_at?->format('Y-m-d') }}</time><a href="{{ route('parent.announcements.show', $announcement) }}">فتح الإعلان ←</a></footer>
            </article>
        @empty
            <div class="empty-state">
                <h2>لا توجد إعلانات</h2>
                <p>تظهر هنا الإعلانات المنشورة لولي الأمر أو صفوف الأبناء.</p>
            </div>
        @endforelse
        {{ $announcements->links() }}
    </section>
</div>
@endsection
