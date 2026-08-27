<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\Student;

class ExamAttemptPolicy
{
    public function maximumAttempts(Exam $exam): int
    {
        return max(1, (int) config('student_academic.exams.default_attempt_limit', 1));
    }

    public function allowsAnotherAttempt(Exam $exam, Student $student, int $existingAttempts): bool
    {
        return $existingAttempts < $this->maximumAttempts($exam);
    }
}
