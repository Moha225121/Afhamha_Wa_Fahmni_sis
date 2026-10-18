<?php

namespace App\Http\Requests\TeacherPortal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isTeacher() && ($this->user()?->hasPermission('homework.manage') ?? false);
    }

    public function rules(): array
    {
        return [
            'creation_token' => [Rule::requiredIf($this->routeIs('teacher.assignments.store')), 'nullable', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'due_date' => ['required', 'date', 'date_format:Y-m-d'],
            'max_score' => ['required', 'numeric', 'min:0.5'],
            'description' => ['nullable', 'string'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png', 'max:20480'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png', 'max:20480'],
        ];
    }
}
