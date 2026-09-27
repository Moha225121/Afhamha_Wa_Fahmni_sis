<?php

namespace App\Http\Controllers\TeacherPortal\Concerns;

use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait InteractsWithTeacherScope
{
    private function teacher(Request $request): Teacher
    {
        return $request->user()->teacher()->with('user')->firstOrFail();
    }

    /**
     * All (classroom_id, subject_id) pairs this teacher is assigned to teach.
     */
    private function assignmentPairs(Teacher $teacher): Collection
    {
        return DB::table('teacher_assignments')
            ->where('teacher_id', $teacher->id)
            ->select('classroom_id', 'subject_id')
            ->get();
    }

    private function assignedClassroomIds(Teacher $teacher): Collection
    {
        return $this->assignmentPairs($teacher)->pluck('classroom_id')->unique()->values();
    }

    private function assignedSubjectIds(Teacher $teacher, ?int $classroomId = null): Collection
    {
        $pairs = $this->assignmentPairs($teacher);

        if ($classroomId !== null) {
            $pairs = $pairs->where('classroom_id', $classroomId);
        }

        return $pairs->pluck('subject_id')->unique()->values();
    }

    /**
     * Whether the teacher is assigned to teach this exact classroom+subject combination.
     */
    private function ownsPair(Teacher $teacher, int $classroomId, int $subjectId): bool
    {
        return $this->assignmentPairs($teacher)
            ->contains(fn ($pair) => (int) $pair->classroom_id === $classroomId && (int) $pair->subject_id === $subjectId);
    }

    /**
     * Whether the given student belongs to one of the teacher's assigned classrooms.
     */
    private function ownsStudent(Teacher $teacher, int $studentId): bool
    {
        $classroomIds = $this->assignedClassroomIds($teacher);

        return DB::table('students')
            ->where('id', $studentId)
            ->whereIn('classroom_id', $classroomIds)
            ->exists();
    }
}
