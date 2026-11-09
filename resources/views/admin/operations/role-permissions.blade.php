@php
    $selectedPermissions = (string) old('_role_form') === $formKey ? old('permissions', []) : $selectedPermissions;
@endphp
<fieldset class="permission-list"><legend>الصلاحيات</legend>
@foreach($catalog ?? [] as $permission)
    @php($inputId = 'permission-'.$formKey.'-'.str_replace('.', '-', $permission))
    <label class="permission-option" for="{{ $inputId }}">
        <input id="{{ $inputId }}" type="checkbox" name="permissions[]" value="{{ $permission }}" @checked(in_array($permission, $selectedPermissions, true))>
        <span>{{ config('permissions.labels')[$permission] ?? $permission }}</span>
    </label>
@endforeach
</fieldset>
