<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupervisorRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->isAdmin() ?? false; }

    public function rules(): array
    {
        $supervisor = $this->route('supervisor');
        return [
            'name' => ['required','string','max:255'],
            'email' => ['required','email',Rule::unique('users')->ignore($supervisor?->id)],
            'phone' => ['nullable','string','max:30'],
            'status' => ['required',Rule::in(['active','inactive'])],
            'password' => [$supervisor ? 'nullable' : 'required','string','min:8','confirmed'],
            'classroom_ids' => ['required','array','min:1'],
            'classroom_ids.*' => ['integer','distinct','exists:classrooms,id'],
        ];
    }
}
