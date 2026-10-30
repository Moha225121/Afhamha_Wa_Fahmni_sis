@extends('teacher.layout')
@section('title', 'مراجعة الرسالة')
@section('subtitle', '')
@section('content')
<link rel="stylesheet" href="{{ asset('css/teacher-messaging.css') }}">
@php
    $reviewGroups = $recipientGroups ?? null;
    $reviewStudentCount = $studentCount ?? 0;
    $reviewGuardianCount = $guardianCount ?? 0;
@endphp
<section class="tm-review-card" aria-labelledby="message-review-heading">
    <span class="tm-statusbadge" data-state="pending">لم تُرسل الرسالة بعد</span>
    <h2 id="message-review-heading">هل الرسالة والمستلمون صحيحون؟</h2>
    <div class="tm-bubble">
        <strong>{{ $data['title'] }}</strong>
        <p>{{ $data['message'] }}</p>
    </div>
    <div class="tm-review-meta">
        <span>المستلمون: <strong>{{ $count }} حساب</strong></span>
        @if($reviewGroups)
            <span>الطلاب: <strong>{{ $reviewStudentCount }}</strong></span>
            <span>أولياء الأمور: <strong>{{ $reviewGuardianCount }}</strong></span>
        @else
            <span>{{ $audienceLabel }}</span>
        @endif
    </div>

    @if($reviewGroups)
        @foreach(['students' => 'الطلاب', 'guardians' => 'أولياء الأمور'] as $groupKey => $groupTitle)
            @php($groupRecipients = collect($reviewGroups[$groupKey] ?? []))
            @if($groupRecipients->isNotEmpty())
                <details class="tm-step" open>
                    <summary><strong>{{ $groupTitle }} ({{ $groupRecipients->count() }})</strong></summary>
                    <ul class="tm-recipient-list">
                        @foreach($groupRecipients as $recipient)
                            <li>
                                <strong>{{ data_get($recipient, 'name') }}</strong>
                                @if(data_get($recipient, 'context'))
                                    <span class="tm-person-copy"><small>{{ data_get($recipient, 'context') }}</small></span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        @endforeach
    @else
        <p>أسماء للمراجعة: {{ $names->join('، ') }}</p>
    @endif

    <form method="post" action="{{ route('teacher.notifications.store') }}" data-teacher-notification-submit>
        @csrf
        <input type="hidden" name="preview_id" value="{{ $previewId }}">
        <input type="hidden" name="confirmed" value="1">
        <div class="form-actions"><button class="tm-send" type="submit">تأكيد الإرسال إلى {{ $count }} مستلم</button></div>
    </form>
    <form method="post" action="{{ route('teacher.notifications.preview') }}" class="form-actions">
        @csrf
        @foreach(['audience', 'subject_id', 'classroom_id', 'recipient_id', 'selection_mode', 'q', 'title', 'message'] as $field)
            @if(isset($data[$field]) && is_scalar($data[$field]))
                <input type="hidden" name="{{ $field }}" value="{{ $data[$field] }}">
            @endif
        @endforeach
        @foreach(['classroom_ids', 'student_ids', 'guardian_ids'] as $field)
            @foreach((array) ($data[$field] ?? []) as $id)
                <input type="hidden" name="{{ $field }}[]" value="{{ $id }}">
            @endforeach
        @endforeach
        <button class="btn secondary" name="edit" value="1" type="submit">تعديل الرسالة أو المستلمين</button>
    </form>
</section>
@endsection
@section('scripts')<script type="module" src="{{ asset('js/teacher-notifications.js') }}"></script>@endsection
