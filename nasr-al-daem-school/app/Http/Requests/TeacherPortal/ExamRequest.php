<?php

namespace App\Http\Requests\TeacherPortal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isTeacher() && ($this->user()?->hasPermission('exams.manage') ?? false);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'between:1,600'],
            'status' => ['required', Rule::in(['draft', 'scheduled', 'published', 'completed'])],
            'questions' => ['nullable', 'array'],
            'questions.*.type' => ['required', Rule::in(['mcq', 'true_false', 'short_answer'])],
            'questions.*.text' => ['required', 'string'],
            'questions.*.score' => ['required', 'numeric', 'min:0.25'],
            'questions.*.choices' => ['nullable', 'array'],
            'questions.*.choices.*.text' => ['nullable', 'string', 'max:500'],
            'questions.*.choices.*.is_correct' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'questions.*.text.required' => 'نص السؤال مطلوب.',
            'questions.*.score.required' => 'درجة السؤال مطلوبة.',
        ];
    }
}
