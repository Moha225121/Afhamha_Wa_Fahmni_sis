<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TeacherRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['assignments' => collect($this->input('assignments', []))->filter(fn ($item) => filled($item['classroom_id'] ?? null) && filled($item['subject_id'] ?? null))->values()->all()]);
    }

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        $teacher = $this->route('teacher');

        return ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', Rule::unique('users')->ignore($teacher?->user_id)], 'phone' => ['nullable', 'string', 'max:30'], 'specialization' => ['nullable', 'string', 'max:255'], 'status' => ['required', Rule::in(['active', 'inactive'])], 'password' => [$teacher ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'], 'assignments' => ['nullable', 'array'], 'assignments.*.classroom_id' => ['required', 'exists:classrooms,id'], 'assignments.*.subject_id' => ['required', 'exists:subjects,id']];
    }
}
