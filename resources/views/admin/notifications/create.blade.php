@extends('admin.layout')
@section('title', 'إرسال إشعار')
@section('subtitle', 'إشعار شخصي إلى مستخدم واحد؛ يُحفظ داخل الموقع ويُرسل إلى أجهزته المفعّلة.')
@section('actions')
    <a class="btn secondary" href="{{ route('admin.notifications.index') }}">إشعاراتي</a>
@endsection
@section('content')
    @if($result = session('notification_result'))
        <div class="alert {{ $result['status'] === 'success' ? 'success' : ($result['status'] === 'error' ? 'error' : '') }}" role="status">
            {{ $result['message'] }}
        </div>
    @endif

    <form class="form-card" method="get" action="{{ route('admin.notifications.create') }}">
        <label for="recipient-search">البحث عن المستلم بالاسم أو البريد الإلكتروني</label>
        <input id="recipient-search" name="q" value="{{ $search }}" maxlength="100">
        <div class="form-actions"><button class="btn secondary" type="submit">بحث</button></div>
        <p class="muted">تظهر حتى 50 نتيجة من الحسابات النشطة. استخدم البحث إذا لم تجد المستلم.</p>
    </form>

    <form class="form-card" method="post" action="{{ route('admin.notifications.store') }}" data-notification-form>
        @csrf
        <input type="hidden" name="submission_id" value="{{ $submissionId }}">
        <div class="form-grid">
            <label class="wide" for="notification-recipient">المستلم
                <select id="notification-recipient" name="recipient_id" required>
                    <option value="">اختر مستخدماً واحداً</option>
                    @foreach($users as $user)
                        <option value="{{ $user->id }}" @selected((string) $selected === (string) $user->id)>{{ $user->name }} — {{ $user->email }} — #{{ $user->id }}</option>
                    @endforeach
                </select>
            </label>
            <label class="wide" for="notification-title">العنوان (حتى 60 حرفاً)
                <input id="notification-title" name="title" value="{{ $draftTitle }}" maxlength="60" required>
            </label>
            <label class="wide" for="notification-message">الرسالة (حتى 300 حرف)
                <textarea id="notification-message" name="message" rows="5" maxlength="300" required>{{ $draftMessage }}</textarea>
            </label>
        </div>
        <p class="muted">تحقق من المستلم قبل الإرسال. ظهور اللافتة يعتمد على إذن المتصفح وإعدادات إشعارات الجهاز.</p>
        <div class="form-actions"><button class="btn primary" type="submit">إرسال الإشعار</button></div>
    </form>
@endsection
@push('scripts')
    <script>
        document.querySelector('[data-notification-form]').addEventListener('submit', function () {
            const button = this.querySelector('button[type="submit"]');
            button.disabled = true;
            button.textContent = 'جارٍ الإرسال…';
        });
    </script>
@endpush
