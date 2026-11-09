@extends('admin.layout')
@section('title','الأدوار والصلاحيات')
@section('content')
<style>
.permission-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr));gap:.5rem;border:0;padding:.75rem 0}
.permission-option{display:flex;align-items:center;gap:.65rem;min-height:44px;cursor:pointer;padding:.5rem;border:1px solid var(--line);border-radius:8px}
.permission-option input{flex:0 0 18px}.permission-option:focus-within{outline:2px solid var(--orange);outline-offset:2px}
.role-form label:not(.permission-option){display:block;margin:.5rem 0}.role-form input:not([type=checkbox]){max-width:100%}
</style>
<form class="panel role-form" method="post" action="{{ route('admin.roles.store') }}">
    @csrf
    <input type="hidden" name="_role_form" value="create">
    <h2>إنشاء دور</h2>
    <label>اسم الدور<input name="name" value="{{ old('_role_form') === 'create' ? old('name') : '' }}" required maxlength="120"></label>
    <label>الوصف<input name="description" value="{{ old('_role_form') === 'create' ? old('description') : '' }}" maxlength="1000"></label>
    @include('admin.operations.role-permissions', ['formKey' => 'create', 'selectedPermissions' => []])
    <button class="btn primary">إنشاء الدور</button>
</form>
<div class="cards">
@foreach($roles as $role)
    @php($protectedRole = $role->isProtectedFromDeletion())
    <article class="card">
        <h2>{{ config('permissions.staff_role_labels')[$role->name] ?? $role->name }}</h2>
        <p>{{ $role->description }}</p>
        @foreach($role->permissions as $permission)<span class="badge">{{ config('permissions.labels')[$permission->permission] ?? $permission->permission }}</span>@endforeach
        <details><summary>تعديل الدور والصلاحيات</summary>
            <form class="role-form" method="post" action="{{ route('admin.roles.update', $role) }}">
                @csrf @method('put')
                <input type="hidden" name="_role_form" value="{{ $role->id }}">
                <label>اسم الدور<input name="name" value="{{ (string) old('_role_form') === (string) $role->id ? old('name') : $role->name }}" required maxlength="120" @readonly($protectedRole)></label>
                <label>الوصف<input name="description" value="{{ (string) old('_role_form') === (string) $role->id ? old('description') : $role->description }}" maxlength="1000"></label>
                @include('admin.operations.role-permissions', ['formKey' => (string) $role->id, 'selectedPermissions' => $role->permissions->pluck('permission')->all()])
                <button class="btn primary">حفظ الدور</button>
            </form>
        </details>
        @if($protectedRole)
            <p class="muted">دور محمي من الحذف.</p>
        @elseif($role->users()->exists())
            <p class="muted">الدور مسند إلى حسابات ولا يمكن حذفه.</p>
        @else
            <form method="post" action="{{ route('admin.roles.destroy', $role) }}">@csrf @method('delete')
                <button class="btn danger" type="submit" data-confirm="حذف هذا الدور المخصص غير المسند وصلاحياته؟">حذف الدور</button>
            </form>
        @endif
    </article>
@endforeach
</div>
@endsection
