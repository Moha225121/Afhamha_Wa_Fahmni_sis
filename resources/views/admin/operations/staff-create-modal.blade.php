<dialog id="staff-create-modal" class="user-modal staff-modal" aria-labelledby="staff-create-title">
    <div class="staff-modal__head"><div><p class="eyebrow">فريق المدرسة</p><h2 id="staff-create-title">إنشاء موظف إداري</h2><p class="muted">حدد مسؤوليات الموظف والصفوف التي يمكنه التعامل معها.</p></div><button type="button" class="staff-modal__close" onclick="this.closest('dialog').close()" aria-label="إغلاق">×</button></div>
    <form method="post" action="{{ route('admin.users.staff.store') }}" id="staff-create-form">@csrf
        <input type="hidden" name="_staff_create" value="1">
        <section class="staff-form-section"><h3><span>1</span> بيانات الموظف</h3><div class="staff-fields">
            <label for="staff-name">الاسم الكامل<input id="staff-name" name="name" value="{{ old('name') }}" required maxlength="255" autocomplete="name" placeholder="اسم الموظف كاملًا"></label>
            <label for="staff-email">البريد الإلكتروني<input id="staff-email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" dir="ltr" placeholder="name@nds.com.ly"></label>
            <label for="staff-phone">رقم الهاتف<input id="staff-phone" type="tel" name="phone" value="{{ old('phone') }}" autocomplete="tel" dir="ltr" placeholder="09xxxxxxxx" maxlength="30"></label>
            <div class="staff-hint">يمكن ترك كلمة المرور فارغة لاستخدام كلمة المرور الافتراضية المعتمدة لدى الإدارة.</div>
            <label for="staff-password">كلمة المرور <small class="muted">(اختياري)</small><input id="staff-password" type="password" name="password" minlength="8" autocomplete="new-password"></label>
            <label for="staff-password-confirmation">تأكيد كلمة المرور<input id="staff-password-confirmation" type="password" name="password_confirmation" minlength="8" autocomplete="new-password"></label>
        </div></section>
        <fieldset class="staff-form-section"><legend><span>2</span> الأدوار والصلاحيات</legend><p class="muted">يمكن اختيار أكثر من دور. تظهر البوابات الناتجة عن اختيارك أدناه.</p><div class="staff-role-grid">
            @foreach($staffRoles as $role)
                <label class="staff-role-card"><input type="checkbox" name="role_ids[]" value="{{ $role->id }}" data-staff-role data-permissions="{{ $role->permissions->pluck('permission')->toJson() }}" @checked(in_array($role->id, old('role_ids', [])))><span><strong>{{ config('permissions.staff_role_labels')[$role->name] ?? $role->name }}</strong><span class="staff-role-permissions">@foreach($role->permissions as $permission)<span>{{ config('permissions.labels')[$permission->permission] ?? 'صلاحية مخصصة' }}</span>@endforeach</span></span></label>
            @endforeach
        </div>@if($staffRoles->isEmpty())<p class="staff-hint">أضف دورًا من شاشة الصلاحيات قبل إنشاء الموظف.</p>@endif
        <div class="staff-preview" aria-live="polite"><b>الشاشات والبوابات المتاحة للموظف</b><p data-staff-preview-empty class="muted">اختر دورًا لعرض البوابات التي سيتمكن الموظف من استخدامها.</p><div class="staff-portal-chips">@foreach(\App\Services\StaffAccess::MODULES as $key => $module)<span hidden data-staff-portal data-permissions="{{ json_encode($module['permissions']) }}">{{ $module['label'] }}</span>@endforeach</div></div>
        </fieldset>
        <fieldset class="staff-form-section"><legend><span>3</span> نطاق الصفوف</legend><div class="staff-scope-options"><label><input type="radio" name="scope_mode" value="all" @checked(old('scope_mode', 'all') === 'all')> كل الصفوف</label><label><input type="radio" name="scope_mode" value="selected" @checked(old('scope_mode') === 'selected')> صفوف محددة</label></div>
            <div data-staff-classrooms hidden><div class="staff-class-tools"><input type="search" data-staff-class-search placeholder="ابحث عن صف أو شعبة" aria-label="البحث في نطاق الصفوف"><button class="btn secondary" type="button" data-staff-class-all>تحديد الكل</button><button class="btn secondary" type="button" data-staff-class-clear>مسح الاختيار</button></div><div class="staff-class-grid">@foreach($classrooms as $classroom)<label data-staff-class><input type="checkbox" name="classroom_ids[]" value="{{ $classroom->id }}" @checked(in_array($classroom->id, old('classroom_ids', [])))><span>{{ $classroom->name }} <small>{{ $classroom->section }}</small></span></label>@endforeach</div><p class="muted" data-staff-class-count></p></div>
            <p class="staff-hint">يشمل النطاق عرض البيانات وتنفيذ العمليات والإشعارات في البوابات المختارة.</p>
        </fieldset>
        <div class="staff-modal__footer"><button type="button" class="btn secondary" onclick="this.closest('dialog').close()">إلغاء</button><button class="btn primary" @disabled($staffRoles->isEmpty())>إنشاء الحساب</button></div>
    </form>
</dialog>
@push('scripts')<script src="{{ asset('js/staff-form.js') }}?v=1" defer></script>@if(old('_staff_create'))<script>document.getElementById('staff-create-modal').showModal();</script>@endif @endpush
