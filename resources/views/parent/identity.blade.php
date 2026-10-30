@extends('parent.layout')
@section('title', 'من سيستخدم المنصة؟')
@section('content')
    <section class="page-title">
        <p>حساب ولي الأمر</p>
        <h1>من سيستخدم المنصة؟</h1>
    </section>
    <section class="identity-panel">
        <p>اختر اسمك للمتابعة. يمكنك تغيير المستخدم لاحقًا من قائمة «المزيد».</p>
        <form method="post" action="{{ route('parent.identity.store') }}" class="identity-options">
            @csrf
            @foreach($options as $key => $identity)
                <button type="submit" name="identity" value="{{ $key }}" class="identity-option" @if($selectedKey === $key) aria-current="true" @endif>
                    <span class="identity-avatar" aria-hidden="true">{{ mb_substr($identity['name'], 0, 1) }}</span>
                    <span class="identity-copy">
                        <strong>{{ $identity['name'] }}</strong>
                        <small>{{ $identity['relationship'] ?: ($key === 'primary' ? 'ولي الأمر الأساسي' : 'ولي أمر آخر') }}</small>
                        @if($identity['phone'])<small dir="ltr">{{ $identity['phone'] }}</small>@endif
                    </span>
                    <span class="identity-action">{{ $selectedKey === $key ? 'المستخدم الحالي' : 'اختيار' }}</span>
                </button>
            @endforeach
        </form>
    </section>
@endsection
