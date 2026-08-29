@extends('parent.layout')

@section('title', 'بدء محادثة')

@section('content')
    <section class="page-title"><p>إدارة المدرسة أو معلم الابن</p><h1>بدء محادثة</h1></section>

    @if($children->isEmpty())
        <section class="empty-state"><h2>لا يوجد أبناء مرتبطون</h2><p>لا يمكن بدء محادثة من دون ابن مرتبط بالحساب.</p></section>
    @else
        @include('parent.partials.child-switcher')
        <form method="post" action="{{ route('parent.conversations.store') }}" class="profile-form section-gap">
            @csrf
            <input type="hidden" name="student_id" value="{{ $selectedStudent?->id }}">
            <label>الابن<input value="{{ $selectedStudent?->user?->name }}" disabled></label>
            <label>المستلم
                <select name="recipient_id" required>
                    <option value="">اختر المستلم</option>
                    @foreach($recipients as $recipient)
                        <option value="{{ $recipient->id }}" @selected(old('recipient_id') == $recipient->id)>{{ $recipient->name }} — {{ $recipient->role === 'admin' ? 'إدارة المدرسة' : 'معلم الصف' }}</option>
                    @endforeach
                </select>
            </label>
            <label>الموضوع<input name="subject" value="{{ old('subject') }}" maxlength="180" placeholder="مثال: استفسار أكاديمي"></label>
            <label>الرسالة<textarea name="body" required maxlength="3000" rows="6" placeholder="اكتب رسالتك هنا">{{ old('body') }}</textarea></label>
            @if($recipients->isEmpty())
                <p class="muted-line">لا يوجد مستلم متاح لهذا الابن حاليًا. راجع ربط المعلمين أو الإدارة.</p>
            @endif
            <button type="submit" @disabled($recipients->isEmpty())>إرسال الرسالة</button>
        </form>
    @endif
@endsection
