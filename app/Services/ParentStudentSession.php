<?php

namespace App\Services;

use App\Models\Guardian;
use App\Models\Student;

class ParentStudentSession
{
    public const PARENT_ID = 'parent_student_session.parent_id';

    public const STUDENT_ID = 'parent_student_session.student_id';

    public function canOpen(Student $student): bool
    {
        if (! config('nasbia.parent_student_session', false)) {
            return false;
        }

        if ($student->status !== 'active' || ! $student->user?->isStudent()) {
            return false;
        }

        $classroom = $student->classroom;
        if (! $classroom) {
            return false;
        }

        $name = mb_strtolower((string) $classroom->name);
        $normalized = preg_replace('/[\sـ_-]+/u', '', $name) ?? $name;
        $arabicGrade = (bool) preg_match('/^(?:الصف)?(?:ال)?(?:اول|الأول|أول|ثاني|الثاني|ثالث|الثالث|رابع|الرابع|خامس|الخامس|سادس|السادس)$/u', $normalized);
        $numberedGrade = (bool) preg_match('/^(?:grade)?[1-6]$/', $normalized);
        $rec = str_contains($normalized, 'rec') || str_contains($normalized, 'روضة') || str_contains($normalized, 'تمهيدي');

        return $arabicGrade || $numberedGrade || $rec;
    }

    public function belongsTo(Guardian $guardian, Student $student): bool
    {
        return $guardian->students()->whereKey($student->id)->exists();
    }

    public function validActiveSession(int $parentId, int $studentId, int $currentUserId): bool
    {
        $student = Student::with(['user', 'classroom'])->find($studentId);
        $guardian = Guardian::where('user_id', $parentId)->where('status', 'active')->first();

        return $student !== null
            && $student->user_id === $currentUserId
            && $student->user?->isStudent()
            && $this->canOpen($student)
            && $guardian !== null
            && $this->belongsTo($guardian, $student);
    }

    public function matchesCurrentStudent(int $studentId, int $currentUserId): bool
    {
        return Student::whereKey($studentId)->where('user_id', $currentUserId)->exists();
    }
}
