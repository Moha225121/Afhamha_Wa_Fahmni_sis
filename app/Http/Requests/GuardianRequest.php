<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        $g = $this->route('parent');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users')->ignore($g?->user_id)],
            'phone' => ['required', 'string', 'max:30'],
            'relationship' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'student_ids' => ['nullable', 'array'],
            'student_ids.*' => ['integer', 'exists:students,id'],
            'additional_guardian_enabled' => ['sometimes', 'boolean'],
            'additional_guardian_name' => ['exclude_unless:additional_guardian_enabled,1', 'required', 'string', 'max:255'],
            'additional_guardian_relationship' => ['exclude_unless:additional_guardian_enabled,1', 'required', 'string', 'max:100'],
            'additional_guardian_phone' => ['exclude_unless:additional_guardian_enabled,1', 'required', 'string', 'max:30'],
        ];
    }

    public function additionalGuardianDetails(): array
    {
        // Omitted fields on existing API clients preserve the current assignment.
        if (! $this->has('additional_guardian_enabled')) {
            return [];
        }

        $details = [];
        foreach (['name', 'relationship', 'phone'] as $field) {
            $key = 'additional_guardian_'.$field;
            $details[$key] = $this->boolean('additional_guardian_enabled') ? $this->validated($key) : null;
        }

        return $details;
    }

    public function messages(): array
    {
        return [
            'additional_guardian_name.required' => 'يرجى إدخال اسم ولي الأمر الآخر عند تفعيل الإسناد.',
            'additional_guardian_phone.required' => 'يرجى إدخال رقم هاتف ولي الأمر الآخر عند تفعيل الإسناد.',
            'additional_guardian_relationship.required' => 'يرجى إدخال صلة قرابة ولي الأمر الآخر عند تفعيل الإسناد.',
        ];
    }
}
