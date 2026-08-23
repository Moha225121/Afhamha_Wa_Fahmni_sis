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

        return ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', Rule::unique('users')->ignore($g?->user_id)], 'phone' => ['required', 'string', 'max:30'], 'relationship' => ['nullable', 'string', 'max:100'], 'status' => ['required', Rule::in(['active', 'inactive'])], 'password' => [$g ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'], 'student_ids' => ['nullable', 'array'], 'student_ids.*' => ['integer', 'exists:students,id']];
    }
}
