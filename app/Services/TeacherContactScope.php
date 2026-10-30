<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;

class TeacherContactScope
{
    /** @return Collection<int, User> */
    public function teachersFor(Student $student): Collection
    {
        if ($student->status !== 'active' || ! $student->classroom_id
            || $student->user?->status !== 'active' || $student->user?->role !== 'student') {
            return collect();
        }

        return User::query()->where('role', 'teacher')->where('status', 'active')
            ->whereHas('teacher', function ($teachers) use ($student): void {
                $teachers->where('status', 'active')->whereExists(function ($assignments) use ($student): void {
                    $assignments->selectRaw('1')->from('teacher_assignments as contact_assignment')
                        ->join('classrooms as contact_classroom', 'contact_classroom.id', '=', 'contact_assignment.classroom_id')
                        ->join('subjects as contact_subject', 'contact_subject.id', '=', 'contact_assignment.subject_id')
                        ->join('classroom_subject as contact_curriculum', function ($join): void {
                            $join->on('contact_curriculum.classroom_id', '=', 'contact_assignment.classroom_id')
                                ->on('contact_curriculum.subject_id', '=', 'contact_assignment.subject_id');
                        })
                        ->whereColumn('contact_assignment.teacher_id', 'teachers.id')
                        ->where('contact_assignment.classroom_id', $student->classroom_id)
                        ->whereNull('contact_classroom.deleted_at')
                        ->where('contact_subject.status', 'active');
                });
            })->orderBy('name')->get(['id', 'name', 'role']);
    }

    public function allows(Student $student, User $teacher): bool
    {
        return $this->teachersFor($student)->contains('id', $teacher->id);
    }
}
