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
    public const MAX_ASSIGNMENTS = 6;

    private array $resolvedAssignments = [];

    public function expandedAssignments(): array
    {
        return $this->resolvedAssignments;
    }

    protected function prepareForValidation(): void
    {
        $rows = $this->input('assignments') ?? [];
        if (is_array($rows)) {
            // Only a completely empty row represents an unused assignment slot.
            $this->merge(['assignments' => array_values(array_filter($rows, fn ($row) =>
                !is_array($row) || filled($row['classroom_id'] ?? null) || filled($row['subject_id'] ?? null)
            ))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        $teacher = $this->route('teacher');
        // Existing accounts may have more rows after expanding all sections.
        $assignmentLimit = max(self::MAX_ASSIGNMENTS, $teacher
            ? DB::table('teacher_assignments')->where('teacher_id', $teacher->id)->count()
            : 0);

        return [
            'name' => ['required', 'string', 'max:255'],
            'first_name_en' => ['nullable', 'required_without:email', 'string', 'max:80', 'regex:/^[a-zA-Z][a-zA-Z -]*$/'],
            'last_name_en' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z -]+$/'],
            'email' => [Rule::requiredIf((bool) $teacher || !app(\App\Services\SchoolAccountService::class)->settings()['teacher_auto_email']), 'nullable', 'max:254', new \App\Rules\SchoolEmail, Rule::unique('users')->ignore($teacher?->user_id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'assignments' => ['array', 'max:'.$assignmentLimit],
            'assignments.*' => ['array'],
            'assignments.*.classroom_id' => ['bail', 'required', 'regex:/^(?:[1-9][0-9]*|all:[1-9][0-9]*)$/'],
            'assignments.*.subject_id' => ['bail', 'required', 'integer', 'exists:subjects,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'assignments.max' => 'يمكن إضافة 6 إسنادات كحد أقصى، مع الاحتفاظ بالإسنادات السابقة.',
            'assignments.*.classroom_id.required' => 'اختر الصف والشعبة للإسناد :position.',
            'assignments.*.subject_id.required' => 'اختر مادة للإسناد :position.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->resolvedAssignments = [];
            if ($validator->errors()->has('assignments')) {
                return;
            }

            $hasNewAssignment = false;
            foreach ($this->input('assignments', []) as $index => $assignment) {
                $selection = $assignment['classroom_id'] ?? null;
                $subjectId = $assignment['subject_id'] ?? null;

                if (!$selection || !$subjectId || $validator->errors()->has("assignments.$index.classroom_id") || $validator->errors()->has("assignments.$index.subject_id")) {
                    continue;
                }

                $classroomIds = TeacherAssignmentGroups::resolve((string) $selection);
                $rooms = Classroom::whereKey($classroomIds)->withExists([
                    'subjects as offers_subject' => fn ($query) => $query->where('subjects.id', $subjectId)->where('status', 'active'),
                ])->get();
                if ($rooms->isEmpty() || $rooms->count() !== count($classroomIds)) {
                    $validator->errors()->add("assignments.$index.classroom_id", 'الصف أو مجموعة الشعب المختارة غير متاحة.');
                    continue;
                }
                $teacher = $this->route('teacher');
                $existingRoomIds = $teacher
                    ? DB::table('teacher_assignments')->where('teacher_id', $teacher->id)
                        ->where('subject_id', $subjectId)->whereIn('classroom_id', $classroomIds)->pluck('classroom_id')->flip()
                    : collect();
                foreach ($rooms as $room) {
                    $isExistingAssignment = $existingRoomIds->has($room->id);
                    $isLiteraryClass = TeacherAssignmentGroups::gradeKey((string) $room->section) === 'ادبي';
                    $hasNewAssignment = $hasNewAssignment || !$isExistingAssignment;

                    if ((!$room->offers_subject || $isLiteraryClass) && !$isExistingAssignment) {
                        $message = str_starts_with((string) $selection, 'all:')
                            ? sprintf('اختر في الإسناد %d مادة مشتركة بين جميع شعب الصف.', $index + 1)
                            : sprintf('اختر في الإسناد %d مادة مرتبطة بالصف %s.', $index + 1, $room->nameWithSection());
                        $validator->errors()->add("assignments.$index.subject_id", $message);
                        break;
                    }

                    $this->resolvedAssignments[] = ['classroom_id' => $room->id, 'subject_id' => (int) $subjectId];
                }
            }

            if (count($this->input('assignments', [])) > self::MAX_ASSIGNMENTS && $hasNewAssignment) {
                $validator->errors()->add('assignments', 'يمكن إضافة 6 إسنادات كحد أقصى، مع الاحتفاظ بالإسنادات السابقة.');
            }
        }];
    }
}
