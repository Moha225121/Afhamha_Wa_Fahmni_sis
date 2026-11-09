<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClassroomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        $nameRules = ['bail', 'required', 'string', 'max:100'];
        $section = $this->input('section');
        $year = $this->input('academic_year_id');
        // Match the existing database constraint. NULL sections remain distinct
        // under SQLite/PostgreSQL; do not introduce a new uniqueness policy.
        if (is_string($section) && $section !== '' && filter_var($year, FILTER_VALIDATE_INT) !== false) {
            $nameRules[] = Rule::unique('classrooms', 'name')
                ->where('section', $section)->where('academic_year_id', $year)
                ->ignore($this->route('class'));
        }

        return ['name' => $nameRules, 'stage' => ['required', 'string', 'max:100'], 'section' => ['nullable', 'string', 'max:50'], 'academic_year_id' => ['bail', 'required', 'integer', 'exists:academic_years,id'], 'subject_ids' => ['nullable', 'array'], 'subject_ids.*' => ['bail', 'integer', 'exists:subjects,id'], 'student_ids' => ['nullable', 'array'], 'student_ids.*' => ['bail', 'integer', 'exists:students,id']];
    }
}
