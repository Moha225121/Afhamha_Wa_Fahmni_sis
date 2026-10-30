@extends('parent.layout')
@section('title', $lesson->title)
@section('content')
<section class="page-title"><p>{{ $student->user->name }} · {{ $lesson->subject->name }}</p><h1>{{ $lesson->title }}</h1></section>
<article class="lesson-content"><div class="content-copy">{!! nl2br(e(strip_tags($lesson->content ?? ''))) !!}</div></article>
<a class="text-action" href="{{ route('parent.notifications') }}">العودة إلى الإشعارات</a>
@endsection
