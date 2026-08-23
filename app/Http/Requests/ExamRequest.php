<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('exams.manage') ?? false;
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:255'], 'subject_id' => ['required', 'exists:subjects,id'], 'classroom_id' => ['required', 'exists:classrooms,id'], 'teacher_id' => ['required', 'exists:teachers,id'], 'starts_at' => ['required', 'date'], 'duration_minutes' => ['required', 'integer', 'between:1,600'], 'total_score' => ['required', 'numeric', 'gt:0'], 'status' => ['required', Rule::in(['draft', 'scheduled', 'published', 'completed', 'cancelled'])]];
    }
}
