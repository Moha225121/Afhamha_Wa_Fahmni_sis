@extends('admin.layout')
@section('title', 'ملف ولي الأمر')
@section('actions')<a class="btn primary" href="{{ route('admin.parents.edit', $parent) }}">تعديل</a>@endsection
@section('content')
    <div class="panel">
        <h2>{{ $parent->user->name }}</h2>
        <p>الهاتف: {{ $parent->user->phone }}</p>
        <p>الأبناء: {{ $parent->students->pluck('user.name')->join('، ') ?: 'غير مرتبط' }}</p>
        @if(filled($parent->additional_guardian_name))
            <h3>ولي الأمر الآخر</h3>
            <p>{{ $parent->additional_guardian_name }} — {{ $parent->additional_guardian_relationship }}</p>
            <p>الهاتف: {{ $parent->additional_guardian_phone }}</p>
        @endif
    </div>
@endsection
