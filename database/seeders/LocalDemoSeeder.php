<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
            ['name' => 'ولي أمر تجريبي', 'password' => Hash::make('password123'), 'role' => 'parent', 'status' => 'active', 'phone' => '0911111111'],
        );

        $guardian = Guardian::updateOrCreate(
            ['user_id' => $parentUser->id],
            ['relationship' => 'الأب', 'status' => 'active'],
        );

        $firstStudent = $this->student('student1@example.test', 'أحمد التجريبي', 'S-1001', $classroom);
        $secondStudent = $this->student('student2@example.test', 'سارة التجريبية', 'S-1002', $classroom);

        $guardian->students()->syncWithoutDetaching([$firstStudent->id, $secondStudent->id]);

        $admin = User::updateOrCreate(
            ['email' => 'admin@example.test'],
            ['name' => 'مدير النظام', 'password' => Hash::make('password123'), 'role' => 'admin', 'status' => 'active'],
        );

        $teacherUser = User::updateOrCreate(
            ['email' => 'teacher1@example.test'],
            ['name' => 'المعلمة مريم', 'password' => Hash::make('password123'), 'role' => 'teacher', 'status' => 'active'],
        );
        $teacher = Teacher::updateOrCreate(['user_id' => $teacherUser->id], ['specialization' => 'رياضيات', 'status' => 'active']);
        $subject = Subject::updateOrCreate(
            ['code' => 'MATH-1'],
            ['name' => 'الرياضيات', 'stage' => 'أساسي', 'description' => 'المادة التجريبية', 'status' => 'active'],
        );

        DB::table('classroom_subject')->updateOrInsert(['classroom_id' => $classroom->id, 'subject_id' => $subject->id]);
        DB::table('teacher_assignments')->updateOrInsert([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
        ]);

        DB::table('grade_sheets')->updateOrInsert(
            ['teacher_id' => $teacher->id, 'classroom_id' => $classroom->id],
            [
                'sheet_data' => json_encode([
                    ['key' => 'monthly', 'title' => 'اختبار شهري', 'weight' => 20],
                    ['key' => 'midterm', 'title' => 'اختبار نصفي', 'weight' => 20],
                    ['key' => 'work', 'title' => 'أعمال', 'weight' => 20],
                    ['key' => 'activity', 'title' => 'نشاط', 'weight' => 20],
                ]),
                'scores' => json_encode([
                    (string) $firstStudent->id => 90,
                    (string) $secondStudent->id => 80,
                ]),
                'updated_at' => now(),
            ],
        );

        DB::table('attendance_records')->updateOrInsert(
            ['student_id' => $firstStudent->id, 'date' => now()->subDay()->toDateString()],
            ['classroom_id' => $classroom->id, 'status' => 'present', 'recorded_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()],
        );
        DB::table('attendance_records')->updateOrInsert(
            ['student_id' => $secondStudent->id, 'date' => now()->subDay()->toDateString()],
            ['classroom_id' => $classroom->id, 'status' => 'late', 'recorded_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()],
        );

        DB::table('exams')->updateOrInsert(
            ['title' => 'اختبار الوحدة الأولى', 'classroom_id' => $classroom->id],
            [
                'subject_id' => $subject->id,
                'teacher_id' => $teacher->id,
                'starts_at' => now()->subDays(3),
                'duration_minutes' => 45,
                'total_score' => 20,
                'status' => 'published',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        $exam = DB::table('exams')->where('title', 'اختبار الوحدة الأولى')->where('classroom_id', $classroom->id)->first();

        DB::table('grades')->updateOrInsert(
            ['exam_id' => $exam->id, 'student_id' => $firstStudent->id],
            ['score' => 18, 'published_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()],
        );
        DB::table('grades')->updateOrInsert(
            ['exam_id' => $exam->id, 'student_id' => $secondStudent->id],
            ['score' => 16, 'published_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()],
        );

        $assignment = Assignment::updateOrCreate(
            ['title' => 'حل تمارين الجمع', 'classroom_id' => $classroom->id],
            [
                'subject_id' => $subject->id,
                'teacher_id' => $teacher->id,
                'instructions' => 'حل التمارين من صفحة 12 ورفع الحل قبل الموعد.',
                'description' => 'حل التمارين من صفحة 12 ورفع الحل قبل الموعد.',
                'due_at' => now()->addDays(2),
                'due_date' => now()->addDays(2)->toDateString(),
                'status' => 'published',
                'published_at' => now()->subHour(),
            ],
        );
        AssignmentSubmission::updateOrCreate(
            ['assignment_id' => $assignment->id, 'student_id' => $firstStudent->id],
            ['status' => 'submitted', 'submitted_at' => now()->subMinutes(30)],
        );

        Announcement::updateOrCreate(
            ['title' => 'تنبيه لأولياء الأمور'],
            [
                'content' => 'نرجو متابعة الواجبات والنتائج من خلال بوابة ولي الأمر.',
                'audience' => 'parents',
                'classroom_id' => $classroom->id,
                'published_at' => now()->subHour(),
                'status' => 'published',
                'created_by' => $admin->id,
            ],
        );
    }

    private function student(string $email, string $name, string $number, Classroom $classroom): Student
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make('password123'), 'role' => 'student', 'status' => 'active'],
        );

        return Student::updateOrCreate(
            ['student_number' => $number],
            ['user_id' => $user->id, 'classroom_id' => $classroom->id, 'status' => 'active'],
        );
    }
}
