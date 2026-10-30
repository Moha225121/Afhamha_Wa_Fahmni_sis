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
        $request = request();
        $sessionKey = 'parent_selected_student.'.$guardian->id;
        $explicit = $studentId !== null && $studentId !== '';
        if ($explicit) {
            abort_unless((is_int($studentId) || is_string($studentId)) && filter_var($studentId, FILTER_VALIDATE_INT) > 0, 404);
            $student = $children->firstWhere('id', (int) $studentId);
            abort_unless($student, 404);
        } else {
            $saved = $request->hasSession() ? $request->session()->get($sessionKey) : null;
            $student = $children->firstWhere('id', $saved) ?? $children->first();
        }

        // An explicit URL belongs to its tab. Background refreshes and POSTs must
        // never change the child selected by ordinary navigation in another tab.
        if ($request->hasSession() && $request->isMethod('GET') && ! $request->headers->has('X-Messenger-Refresh')) {
            if ($student) {
                $request->session()->put($sessionKey, $student->id);
            } else {
                $request->session()->forget($sessionKey);
            }
        }

        return $student;
    }

    public function assertChild(Guardian $guardian, Student $student): void
    {
        abort_unless($guardian->students()->whereKey($student->id)->exists(), 404);
    }
}
