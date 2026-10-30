<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\StaffRole;
use App\Models\StaffRoleAssignment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NisbiyaAccountDeletionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cannot_hard_delete_accounts_or_their_existing_relationships(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $users = collect(['student', 'teacher', 'parent', 'staff'])->mapWithKeys(fn ($role) => [
            $role => User::factory()->create(['role' => $role, 'status' => 'active']),
        ]);
        $year = AcademicYear::create(['name' => 'QA Account Safety', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $room = Classroom::create(['name' => 'السابع', 'section' => 'أ', 'stage' => 'إعدادي', 'academic_year_id' => $year->id]);
        $subject = Subject::create(['name' => 'QA Subject', 'code' => 'QA-ACCOUNT', 'stage' => 'إعدادي', 'status' => 'active']);
        $student = Student::create(['user_id' => $users['student']->id, 'classroom_id' => $room->id, 'student_number' => 'QA-ACCOUNT-SAFE', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $users['teacher']->id, 'status' => 'active']);
        $teacher->classrooms()->attach($room->id, ['subject_id' => $subject->id]);
        $guardian = Guardian::create(['user_id' => $users['parent']->id, 'status' => 'active']);
        $guardian->students()->attach($student);
        $role = StaffRole::create(['name' => 'QA Account Preservation', 'active' => true]);
        $assignment = StaffRoleAssignment::create(['user_id' => $users['staff']->id, 'staff_role_id' => $role->id, 'active' => true, 'scope' => ['classroom_ids' => [$room->id]]]);
        $beforeUsers = $users->map(fn (User $user) => $user->fresh()->getAttributes())->all();

        foreach ($users as $user) {
            $this->actingAs($admin)->delete('/admin/users/'.$user->id)->assertStatus(405);
        }

        $this->assertDatabaseCount('users', 5);
        foreach ($users as $user) {
            $this->assertSame($beforeUsers[$user->role], $user->fresh()->getAttributes());
        }
        $this->assertDatabaseHas('students', ['id' => $student->id, 'user_id' => $users['student']->id, 'classroom_id' => $room->id]);
        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'user_id' => $users['teacher']->id]);
        $this->assertDatabaseHas('guardians', ['id' => $guardian->id, 'user_id' => $users['parent']->id]);
        $this->assertDatabaseHas('guardian_student', ['guardian_id' => $guardian->id, 'student_id' => $student->id]);
        $this->assertDatabaseHas('teacher_assignments', ['teacher_id' => $teacher->id, 'classroom_id' => $room->id, 'subject_id' => $subject->id]);
        $this->assertDatabaseHas('staff_role_assignments', ['id' => $assignment->id, 'user_id' => $users['staff']->id, 'staff_role_id' => $role->id, 'active' => true]);
        $this->assertSame(['classroom_ids' => [$room->id]], $assignment->fresh()->scope);
    }

    public function test_existing_account_deactivation_keeps_the_student_and_family_relationships(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $student = Student::create(['user_id' => $user->id, 'student_number' => 'QA-DEACTIVATE-SAFE', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => User::factory()->create(['role' => 'parent', 'status' => 'active'])->id, 'status' => 'active']);
        $guardian->students()->attach($student);

        $this->actingAs($admin)->patch('/admin/users/'.$user->id, ['role' => 'student', 'status' => 'inactive'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('inactive', $user->fresh()->status);
        $this->assertDatabaseHas('students', ['id' => $student->id, 'user_id' => $user->id, 'student_number' => 'QA-DEACTIVATE-SAFE']);
        $this->assertDatabaseHas('guardian_student', ['guardian_id' => $guardian->id, 'student_id' => $student->id]);
    }

    public function test_staff_finance_and_received_reports_responses_are_not_stored_by_browsers(): void
    {
        foreach ([['staff', '/staff/dashboard'], ['financial_officer', '/finance'], ['admin', '/received-reports']] as [$role, $url]) {
            $response = $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']))->get($url);
            $response->assertOk()->assertHeader('Pragma', 'no-cache')->assertHeader('Expires', '0');
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'), $url);
            $this->assertTrue($response->headers->hasCacheControlDirective('private'), $url);
        }
    }
}
