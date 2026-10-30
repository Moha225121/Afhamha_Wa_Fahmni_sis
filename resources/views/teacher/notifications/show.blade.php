@extends('teacher.layout')
@section('title', 'متابعة الرسالة')
@section('subtitle', '')
@section('actions')<a class="btn secondary" href="{{ route('teacher.notifications.sent') }}">الرسائل المرسلة</a>@endsection
@section('content')
<link rel="stylesheet" href="{{ asset('css/teacher-messaging.css') }}">
<section class="tm-result" aria-labelledby="message-result-heading">
    <h2 id="message-result-heading">حالة المستلمين</h2>
    <div class="tm-status-counts" role="status">
        <span>المستلمون <strong>{{ $count }}</strong></span>
        <span>تم الإرسال <strong>{{ $stored }}</strong></span>
        <span>تم الاستلام <strong>{{ $deliveredCount ?? 0 }}</strong></span>
        <span>تمت المشاهدة <strong>{{ $viewedCount ?? 0 }}</strong></span>
    </div>
    @if($failed)<p class="tm-error">تعذّر الإرسال إلى بعض المستلمين: {{ $failed }}</p>@endif
    @if($skipped)<p class="tm-helper">لم يُرسل إلى {{ $skipped }} بسبب تغيّر الحساب أو صلاحياته.</p>@endif
    @if($batch && !$batch->finished() && !$batch->cancelled() && $stored + $skipped + $failed < $count)<p class="tm-statusbadge" data-state="pending">جارٍ الإرسال</p>@endif
    @if($batch?->cancelled() && $stored < $count)<p class="tm-error">أُلغي طلب الإرسال.</p>@endif
    @php($receiptRows = collect($perRecipientReceipts ?? []))
    @if($receiptRows->isNotEmpty())
        <div class="tm-status-table-wrap">
            <table class="tm-status-table">
                <thead><tr><th>المستلم</th><th>الحساب</th><th>الحالة</th></tr></thead>
                <tbody>
                    @foreach($receiptRows as $receipt)
                        <tr><td>{{ $receipt->recipient?->name ?? 'حساب غير متاح' }}</td><td>{{ $receipt->recipient?->role === 'parent' ? 'ولي أمر' : 'طالب' }}</td><td><span class="tm-delivery-state" data-state="{{ $receipt->status }}">{{ $receipt->status_label }}</span></td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @php($recovery = session('message_recovery'))
    @if(is_array($recovery) && ($recovery['dispatch'] ?? null) === $dispatch)
        <p class="tm-helper" role="status">
            @if(($recovery['restored'] ?? 0) > 0) تم إرسال {{ $recovery['restored'] }} رسالة إلى حسابات المستلمين.
            @elseif(($recovery['next'] ?? null) === null && !($recovery['failed'] ?? 0)) لم يُعثر على طلب إرسال قابل للاستكمال ضمن الرسائل المعلقة. @endif
            @if($recovery['failed'] ?? 0) تعذّر استكمال بعض الرسائل؛ يمكنك إعادة المحاولة. @endif
            @if(($recovery['next'] ?? null) !== null) اضغط متابعة لاستكمال فحص الرسائل المعلقة. @endif
        </p>
    @endif
    @if($batch && !$batch->cancelled() && $receiptRows->where('status', 'queued')->isNotEmpty())
        <form method="post" action="{{ route('teacher.notifications.recover', $dispatch) }}" class="form-actions">
            @csrf
            @if(is_array($recovery) && ($recovery['dispatch'] ?? null) === $dispatch && ($recovery['next'] ?? null) !== null)
                <input type="hidden" name="after" value="{{ $recovery['next'] }}">
                <button class="tm-send" type="submit">متابعة استكمال الإرسال</button>
            @else
                <button class="tm-send" type="submit">استكمال الإرسال</button>
            @endif
        </form>
    @endif
    <div class="form-actions">
        <a class="btn secondary" href="{{ route('teacher.notifications.show', $dispatch) }}">تحديث الحالة</a>
        <a class="tm-send" href="{{ route('teacher.notifications.index') }}">رسالة جديدة</a>
    </div>
</section>
@endsection
