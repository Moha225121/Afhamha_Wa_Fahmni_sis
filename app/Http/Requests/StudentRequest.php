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

        return ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', Rule::unique('users')->ignore($this->route('student')?->user_id)], 'phone' => ['nullable', 'string', 'max:30'], 'student_number' => ['required', 'string', 'max:50', Rule::unique('students')->ignore($id)], 'classroom_id' => ['nullable', 'exists:classrooms,id'], 'birth_date' => ['nullable', 'date', 'before:today'], 'gender' => ['nullable', Rule::in(['male', 'female'])], 'address' => ['nullable', 'string'], 'status' => ['required', Rule::in(['active', 'inactive'])], 'password' => [$id ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'], 'guardian_ids' => ['nullable', 'array'], 'guardian_ids.*' => ['integer', 'exists:guardians,id']];
    }

    public function messages(): array
    {
        return ['required' => 'حقل :attribute مطلوب.', 'email.email' => 'البريد الإلكتروني غير صالح.', 'unique' => 'قيمة :attribute مستخدمة مسبقًا.', 'password.confirmed' => 'تأكيد كلمة المرور غير مطابق.'];
    }
}
