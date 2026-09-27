<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:150'], 'code' => ['required', 'string', 'max:30', Rule::unique('subjects')->ignore($this->route('subject'))], 'stage' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string'], 'status' => ['required', Rule::in(['active', 'inactive'])], 'classroom_ids' => ['nullable', 'array'], 'classroom_ids.*' => ['exists:classrooms,id']];
    }
}
