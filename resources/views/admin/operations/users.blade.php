@extends('admin.layout')
@section('title','المستخدمون')
@section('content')
<style>.user-actions{display:flex;gap:.75rem;justify-content:flex-start;margin-bottom:1rem}.user-modal{border:0;border-radius:16px;padding:1.5rem;max-width:900px;width:calc(100% - 2rem);max-height:90vh;overflow:auto;box-shadow:0 18px 60px #0005}.user-modal::backdrop{background:#062b35aa}.user-modal__close{float:left;border:0;background:transparent;font-size:1.4rem;cursor:pointer}.user-modal h2{margin-top:0}</style>
<div class="user-actions"><button type="button" class="btn primary" onclick="document.getElementById('staff-create-modal').showModal()">＋ إنشاء موظف إداري</button><button type="button" class="btn secondary" onclick="document.getElementById('supervisor-modal').showModal()">تكليفات المشرفين</button></div>
<form class="filters"><input name="q" value="{{ request('q') }}" placeholder="الاسم أو البريد"><select name="role"><option value="">كل الأدوار</option>
@foreach(['admin'=>'مدير النظام','supervisor'=>'مشرف','teacher'=>'معلم','student'=>'طالب','parent'=>'ولي أمر','staff'=>'موظف إداري'] as $r=>$label)<option value="{{ $r }}">{{ $label }}</option>
@endforeach
</select><button class="btn secondary">بحث</button></form>
<div class="table-wrap"><table><thead><tr><th>الاسم</th><th>البريد</th><th>آخر دخول</th><th>الدور والحالة</th></tr></thead><tbody>

@foreach($users as $u)<tr><td>{{ $u->name }}</td><td>{{ $u->email }}</td><td>{{ $u->last_login_at?->format('Y-m-d H:i')?:'—' }}</td><td><form method="post" action="{{ route('admin.users.update',$u) }}">@csrf @method('patch')<select name="role">
@foreach(['admin'=>'مدير النظام','supervisor'=>'مشرف','teacher'=>'معلم','student'=>'طالب','parent'=>'ولي أمر','staff'=>'موظف إداري'] as $r=>$label)<option value="{{ $r }}" @selected($u->role===$r)>{{ $label }}</option>
@endforeach
</select><select name="status"><option value="active" @selected($u->status==='active')>نشط</option><option value="inactive" @selected($u->status==='inactive')>موقوف</option></select>
<button class="btn secondary">حفظ</button></form></td></tr>
@endforeach

</tbody></table></div>{{ $users->links() }}
<dialog id="supervisor-modal" class="user-modal"><button type="button" class="user-modal__close" onclick="this.closest('dialog').close()" aria-label="إغلاق">×</button><h2>تكليفات المشرفين</h2>
@forelse($users->where('role','supervisor') as $supervisor)<form method="post" action="{{ route('admin.users.update',$supervisor) }}">@csrf @method('patch')<input type="hidden" name="role" value="supervisor"><input type="hidden" name="status" value="{{ $supervisor->status }}"><strong>{{ $supervisor->name }}</strong>
@foreach($classrooms as $classroom)<label><input type="checkbox" name="classroom_ids[]" value="{{ $classroom->id }}" @checked($supervisor->supervisedClassrooms->contains($classroom))> {{ $classroom->name }} {{ $classroom->section }}</label>
@endforeach
<button class="btn secondary">حفظ التكليفات</button></form>
@empty
<p>لا يوجد مشرفون في هذه الصفحة.</p>
@endforelse
</dialog>
<dialog id="staff-create-modal" class="user-modal"><button type="button" class="user-modal__close" onclick="this.closest('dialog').close()" aria-label="إغلاق">×</button><h2>إنشاء موظف إداري</h2><p class="muted">اختر دورًا أو عدة أدوار وحدد الصفوف التي تنطبق عليها الصلاحيات.</p><form method="post" action="{{ route('admin.users.staff.store') }}">@csrf
<label>الاسم الكامل<input name="name" required maxlength="255"></label><label>البريد الإلكتروني<input type="email" name="email" required></label><label>الهاتف<input name="phone"></label><label>كلمة المرور (اختياري)<input type="password" name="password"></label><label>تأكيد كلمة المرور<input type="password" name="password_confirmation"></label>
<fieldset><legend>الأدوار والصلاحيات</legend>@foreach($staffRoles as $role)@php($roleLabels=['Student Affairs Officer'=>'موظف شؤون الطلاب','Academic Follow-up Officer'=>'موظف المتابعة الأكاديمية','Teacher Monitoring Officer'=>'موظف متابعة المعلمين','Parent Communication and Guardian Calls'=>'التواصل واستدعاءات أولياء الأمور','Finance Notifications Officer'=>'موظف إشعارات المالية','Announcements and Communications Officer'=>'موظف الإعلانات والاتصالات','Activities Coordinator'=>'منسق الأنشطة'])<label><input type="checkbox" name="role_ids[]" value="{{ $role->id }}"> <b>{{ $roleLabels[$role->name] ?? $role->name }}</b> — @foreach($role->permissions as $permission){{ config('permissions.labels.'.$permission->permission, $permission->permission) }}@if(!$loop->last)، @endif @endforeach</label>@endforeach</fieldset>
<fieldset><legend>نطاق الصفوف</legend>@foreach($classrooms as $classroom)<label><input type="checkbox" name="classroom_ids[]" value="{{ $classroom->id }}">{{ $classroom->name }} {{ $classroom->section }}</label>@endforeach</fieldset><button class="btn">إنشاء الحساب</button></form></dialog>
@endsection
