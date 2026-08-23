<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;

class LocalDemoSeeder extends Seeder
{
    public function run(): void
    {
        $year = AcademicYear::firstOrCreate(
            ['name' => '2026/2027'],
            ['starts_at' => '2026-09-01', 'ends_at' => '2027-06-30', 'is_current' => true],
        );

        $classroom = Classroom::firstOrCreate(
            ['name' => 'الصف الأول', 'section' => 'أ', 'academic_year_id' => $year->id],
            ['stage' => 'أساسي'],
        );

        $parentUser = User::updateOrCreate(
            ['email' => 'parent@example.test'],
            ['name' => 'ولي أمر تجريبي', 'password' => 'password123', 'role' => 'parent', 'status' => 'active', 'phone' => '0911111111'],
        );

        $guardian = Guardian::updateOrCreate(
            ['user_id' => $parentUser->id],
            ['relationship' => 'الأب', 'status' => 'active'],
        );

        $firstStudent = $this->student('student1@example.test', 'أحمد التجريبي', 'S-1001', $classroom);
        $secondStudent = $this->student('student2@example.test', 'سارة التجريبية', 'S-1002', $classroom);

        $guardian->students()->syncWithoutDetaching([$firstStudent->id, $secondStudent->id]);

        User::updateOrCreate(
            ['email' => 'admin@example.test'],
            ['name' => 'مدير النظام', 'password' => 'password123', 'role' => 'admin', 'status' => 'active'],
        );
    }

    private function student(string $email, string $name, string $number, Classroom $classroom): Student
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => 'password123', 'role' => 'student', 'status' => 'active'],
        );

        return Student::updateOrCreate(
            ['student_number' => $number],
            ['user_id' => $user->id, 'classroom_id' => $classroom->id, 'status' => 'active'],
        );
    }
}
