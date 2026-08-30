<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('schedules.manage') ?? false;
    }

    public function rules(): array
    {
        return ['classroom_id' => ['required', 'exists:classrooms,id'], 'teacher_id' => ['required', 'exists:teachers,id'], 'subject_id' => ['required', 'exists:subjects,id'], 'day_of_week' => ['required', 'integer', 'between:0,4'], 'period_number' => ['required','integer','between:1,12'], 'room' => ['nullable', 'string', 'max:100']];
    }
}
