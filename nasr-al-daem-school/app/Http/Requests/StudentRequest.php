<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        $id = $this->route('student')?->id;

        return ['name' => ['required', 'string', 'max:255'], 'first_name_en' => ['nullable', 'required_without:email', 'string', 'max:80', 'regex:/^[a-zA-Z][a-zA-Z -]*$/'], 'last_name_en' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z -]+$/'], 'email' => [Rule::requiredIf((bool) $id || !app(\App\Services\SchoolAccountService::class)->settings()['student_auto_email']), 'nullable', 'max:254', new \App\Rules\SchoolEmail, Rule::unique('users')->ignore($this->route('student')?->user_id)], 'phone' => ['nullable', 'string', 'max:30'], 'student_number' => [$id ? 'required' : 'nullable', 'string', 'max:100', Rule::unique('students')->ignore($id)], 'classroom_id' => ['nullable', 'exists:classrooms,id'], 'birth_date' => ['nullable', 'date', 'before:today'], 'gender' => ['nullable', Rule::in(['male', 'female'])], 'address' => ['nullable', 'string'], 'status' => ['required', Rule::in(['active', 'inactive'])], 'password' => ['nullable', 'string', 'min:8', 'confirmed'], 'guardian_ids' => ['nullable', 'array'], 'guardian_ids.*' => ['integer', 'exists:guardians,id']];
    }

    public function messages(): array
    {
        return ['required' => 'حقل :attribute مطلوب.', 'email.email' => 'البريد الإلكتروني غير صالح.', 'unique' => 'قيمة :attribute مستخدمة مسبقًا.', 'password.confirmed' => 'تأكيد كلمة المرور غير مطابق.'];
    }
}
