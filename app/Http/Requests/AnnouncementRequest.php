<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:255'], 'content' => ['required', 'string'], 'audience' => ['required', Rule::in(['all', 'students', 'teachers', 'parents', 'classroom'])], 'classroom_id' => ['nullable', 'required_if:audience,classroom', 'exists:classrooms,id'], 'published_at' => ['nullable', 'date'], 'expires_at' => ['nullable', 'date', 'after:published_at'], 'status' => ['required', Rule::in(['draft', 'published', 'archived'])]];
    }
}
