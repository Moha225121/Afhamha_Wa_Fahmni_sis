<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClassroomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100'], 'stage' => ['required', 'string', 'max:100'], 'section' => ['nullable', 'string', 'max:50'], 'academic_year_id' => ['required', 'exists:academic_years,id'], 'subject_ids' => ['nullable', 'array'], 'subject_ids.*' => ['exists:subjects,id']];
    }
}
