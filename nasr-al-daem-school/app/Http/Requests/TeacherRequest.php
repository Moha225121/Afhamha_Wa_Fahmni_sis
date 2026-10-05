<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Classroom;
use App\Services\TeacherAssignmentGroups;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;
use Illuminate\Validation\Rule;

class TeacherRequest extends FormRequest
{
    private array $resolvedAssignments = [];

    public function expandedAssignments(): array
    {
        return $this->resolvedAssignments;
    }

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

        return ['name' => ['required', 'string', 'max:255'], 'first_name_en' => ['nullable', 'required_without:email', 'string', 'max:80', 'regex:/^[a-zA-Z][a-zA-Z -]*$/'], 'last_name_en' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z -]+$/'], 'email' => [Rule::requiredIf((bool) $teacher || !app(\App\Services\SchoolAccountService::class)->settings()['teacher_auto_email']), 'nullable', 'max:254', new \App\Rules\SchoolEmail, Rule::unique('users')->ignore($teacher?->user_id)], 'phone' => ['nullable', 'string', 'max:30'], 'specialization' => ['nullable', 'string', 'max:255'], 'status' => ['required', Rule::in(['active', 'inactive'])], 'password' => ['nullable', 'string', 'min:8', 'confirmed'], 'assignments' => ['nullable', 'array', 'max:30'], 'assignments.*.classroom_id' => ['required', 'regex:/^(?:[1-9][0-9]*|all:[1-9][0-9]*)$/'], 'assignments.*.subject_id' => ['required', 'exists:subjects,id']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->resolvedAssignments = [];
            foreach ($this->input('assignments', []) as $index => $assignment) {
                $selection = $assignment['classroom_id'] ?? null;
                $subjectId = $assignment['subject_id'] ?? null;

                if (!$selection || !$subjectId || $validator->errors()->has("assignments.$index.classroom_id") || $validator->errors()->has("assignments.$index.subject_id")) {
                    continue;
                }

                $classroomIds = TeacherAssignmentGroups::resolve((string) $selection);
                if (!$classroomIds) {
                    $validator->errors()->add("assignments.$index.classroom_id", 'الصف أو مجموعة الشعب المختارة غير متاحة.');
                    continue;
                }

                foreach ($classroomIds as $classroomId) {
                    $isLinkedToClassroom = DB::table('classroom_subject')
                        ->where('classroom_id', $classroomId)
                        ->where('subject_id', $subjectId)
                        ->exists();

                    $isExistingAssignment = $this->route('teacher') && DB::table('teacher_assignments')
                        ->where('teacher_id', $this->route('teacher')->id)
                        ->where('classroom_id', $classroomId)
                        ->where('subject_id', $subjectId)
                        ->exists();
                    $isLiteraryClass = Classroom::whereKey($classroomId)->value('section') === 'أدبي';

                    if ((!$isLinkedToClassroom || $isLiteraryClass) && !$isExistingAssignment) {
                        $validator->errors()->add("assignments.$index.subject_id", 'المادة المختارة غير متاحة لجميع شعب الصف المحددة.');
                        break;
                    }

                    $this->resolvedAssignments[] = ['classroom_id' => $classroomId, 'subject_id' => (int) $subjectId];
                }
            }
        }];
    }
}
