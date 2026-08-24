<?php

namespace Tests\Concerns;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;

trait CreatesStudentEducationFixtures
{
    /**
     * @return array{user: User, student: Student, classroom: Classroom}
     */
    protected function createStudentFixture(string $suffix, string $stage = 'أساسي'): array
    {
        $year = AcademicYear::create([
            'name' => '2026/2027-'.$suffix,
            'starts_at' => '2026-09-01',
            'ends_at' => '2027-06-30',
            'is_current' => true,
        ]);
        $classroom = Classroom::create([
            'name' => 'الصف '.$suffix,
            'stage' => $stage,
            'section' => $suffix,
            'academic_year_id' => $year->id,
        ]);
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);
        $student = Student::create([
            'user_id' => $user->id,
            'student_number' => 'ST-'.$suffix,
            'classroom_id' => $classroom->id,
            'status' => 'active',
        ]);

        return compact('user', 'student', 'classroom');
    }

    protected function createSubjectFor(Classroom $classroom, string $suffix, string $status = 'active'): Subject
    {
        $subject = Subject::create([
            'name' => 'مادة '.$suffix,
            'code' => 'SUB-'.$suffix,
            'stage' => $classroom->stage,
            'description' => 'وصف '.$suffix,
            'status' => $status,
        ]);
        $subject->classrooms()->attach($classroom);

        return $subject;
    }

    protected function createAdmin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }
}
