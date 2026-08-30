<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\LocalDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_login_page_serves_all_main_portals(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSeeText('دخول المنصة')
            ->assertDontSeeText('حساب واحد للوصول إلى بوابة الإدارة أو ولي الأمر أو الطالب.')
            ->assertDontSeeText('أنواع بوابات المنصة');
    }

    public function test_demo_seeded_users_can_authenticate_with_password123(): void
    {
        $this->seed(LocalDemoSeeder::class);

        $user = User::where('email', 'admin@example.test')->firstOrFail();

        $this->assertTrue(Hash::check('password123', $user->password));
        $this->post('/login', ['email' => 'admin@example.test', 'password' => 'password123'])
            ->assertRedirect('/admin/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_student_login_redirects_to_student_portal(): void
    {
        $studentUser = $this->createStudentAccount();

        $this->post('/login', ['email' => $studentUser->email, 'password' => 'password123'])
            ->assertRedirect('/student/dashboard');

        $this->assertAuthenticatedAs($studentUser);
    }

    public function test_root_and_login_show_login_even_when_a_session_exists(): void
    {
        $parentUser = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        Guardian::create(['user_id' => $parentUser->id, 'status' => 'active']);
        $studentUser = $this->createStudentAccount();
        $adminUser = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->get('/')->assertRedirect('/login');
        $this->actingAs($parentUser)->get('/')->assertRedirect('/login');
        $this->actingAs($parentUser)->get('/login')->assertOk();
        $this->actingAs($studentUser)->get('/')->assertRedirect('/login');
        $this->actingAs($studentUser)->get('/login')->assertOk();
        $this->actingAs($adminUser)->get('/')->assertRedirect('/login');
        $this->actingAs($adminUser)->get('/login')->assertOk();

        $this->actingAs($parentUser)->post('/login', [
            'email' => $studentUser->email,
            'password' => 'password123',
        ])->assertRedirect('/student/dashboard');
        $this->assertAuthenticatedAs($studentUser);
    }

    public function test_student_portal_is_only_for_student_accounts(): void
    {
        $studentUser = $this->createStudentAccount();
        $parentUser = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        Guardian::create(['user_id' => $parentUser->id, 'status' => 'active']);

        $this->get('/student/dashboard')->assertRedirect('/login');
        $this->actingAs($parentUser)->get('/student/dashboard')->assertForbidden();
        $this->actingAs($studentUser)->get('/student/dashboard')->assertOk()->assertSeeText($studentUser->name);
    }

    public function test_teacher_login_redirects_to_teacher_portal(): void
    {
        $teacherUser = User::factory()->create([
            'name' => 'Teacher Account',
            'role' => 'teacher',
            'status' => 'active',
            'password' => 'password123',
        ]);

        $this->post('/login', ['email' => $teacherUser->email, 'password' => 'password123'])
            ->assertRedirect('/teacher/dashboard');

        $this->assertAuthenticatedAs($teacherUser);
    }

    public function test_supervisor_login_redirects_to_supervisor_portal(): void
    {
        $supervisor = User::factory()->create([
            'role' => 'supervisor',
            'status' => 'active',
            'password' => 'password123',
        ]);

        $this->post('/login', ['email' => $supervisor->email, 'password' => 'password123'])
            ->assertRedirect('/supervisor/dashboard');

        $this->assertAuthenticatedAs($supervisor);
    }

    public function test_teacher_attendance_is_saved_to_database_and_preserves_selected_date(): void
    {
        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active', 'password' => 'password123']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'specialization' => 'رياضيات', 'status' => 'active']);

        $year = AcademicYear::create([
            'name' => '2026/2027',
            'starts_at' => '2026-09-01',
            'ends_at' => '2027-06-30',
            'is_current' => true,
        ]);

        $classroom = Classroom::create([
            'name' => 'Grade 1',
            'stage' => 'Primary',
            'section' => 'A',
            'academic_year_id' => $year->id,
        ]);

        $studentUser = User::factory()->create(['name' => 'Student One', 'role' => 'student', 'status' => 'active', 'password' => 'password123']);
        $student = Student::create([
            'user_id' => $studentUser->id,
            'student_number' => 'S-ATT',
            'classroom_id' => $classroom->id,
            'status' => 'active',
        ]);

        $subject = Subject::create([
            'code' => 'MATH-ATT',
            'name' => 'Mathematics',
            'stage' => 'Primary',
            'description' => 'Math attendance test subject',
            'status' => 'active',
        ]);

        DB::table('teacher_assignments')->insert([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
        ]);

        $response = $this->actingAs($teacherUser)->post('/teacher/attendance', [
            'date' => '2026-08-15',
            'classroom_id' => $classroom->id,
            'records' => [$student->id => 'late'],
        ]);

        $response->assertRedirect('/teacher/attendance?date=2026-08-15&classroom_id=' . $classroom->id);
        $this->assertDatabaseHas('attendance_records', [
            'student_id' => $student->id,
            'date' => '2026-08-15',
            'status' => 'late',
            'classroom_id' => $classroom->id,
        ]);
    }

    public function test_teacher_can_open_assignment_creation_form(): void
    {
        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active', 'password' => 'password123']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'specialization' => 'رياضيات', 'status' => 'active']);

        $year = AcademicYear::create([
            'name' => '2026/2027',
            'starts_at' => '2026-09-01',
            'ends_at' => '2027-06-30',
            'is_current' => true,
        ]);

        $classroom = Classroom::create([
            'name' => 'Grade 1',
            'stage' => 'Primary',
            'section' => 'A',
            'academic_year_id' => $year->id,
        ]);

        $subject = Subject::create([
            'code' => 'MATH-ASSIGN',
            'name' => 'Mathematics',
            'stage' => 'Primary',
            'description' => 'Assignment subject',
            'status' => 'active',
        ]);

        DB::table('teacher_assignments')->insert([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
        ]);

        $this->actingAs($teacherUser)
            ->get('/teacher/assignments/create')
            ->assertOk()
            ->assertSeeText('إنشاء واجب');
    }

    private function createStudentAccount(): User
    {
        $year = AcademicYear::create([
            'name' => '2026/2027',
            'starts_at' => '2026-09-01',
            'ends_at' => '2027-06-30',
            'is_current' => true,
        ]);

        $classroom = Classroom::create([
            'name' => 'Grade 1',
            'stage' => 'Primary',
            'section' => 'A',
            'academic_year_id' => $year->id,
        ]);

        $studentUser = User::factory()->create([
            'name' => 'Student Account',
            'role' => 'student',
            'status' => 'active',
            'password' => 'password123',
        ]);

        Student::create([
            'user_id' => $studentUser->id,
            'student_number' => 'S-LOGIN',
            'classroom_id' => $classroom->id,
            'status' => 'active',
        ]);

        return $studentUser;
    }
}
