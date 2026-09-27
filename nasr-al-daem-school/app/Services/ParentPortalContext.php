<?php

namespace App\Services;

use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ParentPortalContext
{
    public function guardian(Request $request): Guardian
    {
        return $request->user()->guardian()->with('user')->firstOrFail();
    }

    /** @return Collection<int, Student> */
    public function children(Guardian $guardian): Collection
    {
        return $guardian->students()
            ->with(['user', 'classroom.academicYear'])
            ->orderBy('student_number')
            ->get();
    }

    /** @param Collection<int, Student> $children */
    public function selectedStudent(Guardian $guardian, Collection $children, mixed $studentId): ?Student
    {
        if ($studentId === null || $studentId === '') {
            return $children->first();
        }

        $student = $guardian->students()
            ->with(['user', 'classroom.academicYear'])
            ->whereKey((int) $studentId)
            ->first();

        abort_unless($student, 404);

        return $student;
    }

    public function assertChild(Guardian $guardian, Student $student): void
    {
        abort_unless($guardian->students()->whereKey($student->id)->exists(), 404);
    }
}
