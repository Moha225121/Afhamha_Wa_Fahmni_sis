@extends('admin.layout')
@section('title', $parent->exists ? 'تعديل ولي الأمر' : 'إضافة ولي أمر')
@section('content')
    @php
        $u = $parent->user;
        $additionalEnabled = (bool) old('additional_guardian_enabled', filled($parent->additional_guardian_name));
    @endphp
    <form class="form-card" method="post" action="{{ $parent->exists ? route('admin.parents.update', $parent) : route('admin.parents.store') }}">
        @csrf
        @if($parent->exists) @method('put') @endif
        <div class="form-grid">
            <label>الاسم<input name="name" value="{{ old('name', $u?->name) }}" maxlength="255" required></label>
            <label>البريد<input type="email" name="email" value="{{ old('email', $u?->email) }}" required></label>
            <label>الهاتف<input name="phone" value="{{ old('phone', $u?->phone) }}" maxlength="30" inputmode="tel" required></label>
            <label>صلة القرابة<input name="relationship" value="{{ old('relationship', $parent->relationship) }}" maxlength="100"></label>
            <label>الحالة
                <select name="status">
                    <option value="active" @selected(old('status', $parent->status) !== 'inactive')>نشط</option>
                    <option value="inactive" @selected(old('status', $parent->status) === 'inactive')>موقوف</option>
                </select>
            </label>
            <label class="wide">الأبناء
                <select name="student_ids[]" multiple data-searchable data-placeholder="اختر الأبناء">
                    @foreach($students as $s)
                        <option value="{{ $s->id }}" @selected(in_array($s->id, old('student_ids', session()->hasOldInput() ? [] : ($parent->students?->pluck('id')->all() ?? []))))>{{ $s->user->name }} — {{ $s->student_number }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <fieldset class="additional-guardian-section">
            <legend>إسناد ولي أمر آخر</legend>
            <input type="hidden" name="additional_guardian_enabled" value="0">
            <label class="additional-guardian-toggle">
                <input type="checkbox" name="additional_guardian_enabled" value="1" id="additional-guardian-enabled" aria-controls="additional-guardian-details" aria-expanded="{{ $additionalEnabled ? 'true' : 'false' }}" @checked($additionalEnabled)>
                إضافة الأم أو ولي أمر آخر إلى هذا الحساب
            </label>
            <p class="muted">يستخدم الشخصان بيانات الدخول نفسها ويتابعان الأبناء المرتبطين بهذا الحساب، ويختار كل منهما اسمه عند استخدام المنصة.</p>
            <div class="form-grid" id="additional-guardian-details" @if(!$additionalEnabled) hidden @endif>
                <label>اسم ولي الأمر الآخر<input name="additional_guardian_name" value="{{ old('additional_guardian_name', $parent->additional_guardian_name) }}" maxlength="255" @required($additionalEnabled) @disabled(!$additionalEnabled)></label>
                <label>رقم هاتف ولي الأمر الآخر<input name="additional_guardian_phone" value="{{ old('additional_guardian_phone', $parent->additional_guardian_phone) }}" maxlength="30" inputmode="tel" @required($additionalEnabled) @disabled(!$additionalEnabled)></label>
                <label>صلة القرابة<input name="additional_guardian_relationship" value="{{ old('additional_guardian_relationship', $parent->additional_guardian_relationship ?? 'الأم') }}" maxlength="100" placeholder="الأم، الأب، أو صلة قرابة أخرى" @required($additionalEnabled) @disabled(!$additionalEnabled)></label>
            </div>
            @if($parent->exists && filled($parent->additional_guardian_name))
                <p class="muted">لإلغاء الإسناد، أزل علامة الاختيار ثم احفظ.</p>
            @endif
        </fieldset>

        <div class="form-grid">
            <label>كلمة المرور <small>{{ $parent->exists ? 'اتركها فارغة للإبقاء على كلمة المرور الحالية.' : 'اختياري: اتركها فارغة لاستخدام كلمة المرور الافتراضية المحددة من الإدارة.' }}</small><input type="password" name="password" autocomplete="new-password"></label>
            <label>تأكيد كلمة المرور<input type="password" name="password_confirmation" autocomplete="new-password"></label>
        </div>
        <div class="form-actions"><button class="btn primary">حفظ</button><a class="btn secondary" href="{{ route('admin.parents.index') }}">إلغاء</a></div>
    </form>
@endsection
@push('scripts')
    <script>
        const additionalGuardianToggle = document.getElementById('additional-guardian-enabled');
        const additionalGuardianDetails = document.getElementById('additional-guardian-details');
        const updateAdditionalGuardian = () => {
            const enabled = additionalGuardianToggle.checked;
            additionalGuardianDetails.hidden = !enabled;
            additionalGuardianToggle.setAttribute('aria-expanded', String(enabled));
            additionalGuardianDetails.querySelectorAll('input').forEach(input => {
                input.disabled = !enabled;
                input.required = enabled;
            });
        };
        additionalGuardianToggle.addEventListener('change', updateAdditionalGuardian);
        updateAdditionalGuardian();
    </script>
@endpush
