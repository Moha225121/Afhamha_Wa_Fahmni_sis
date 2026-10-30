<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\GuardianCall;
use App\Models\Lesson;
use App\Models\SchoolActivity;
use App\Models\StaffRole;
use App\Models\StaffRoleAssignment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\StaffAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NisbiyaStaffScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private StaffRole $role;

    private Classroom $room;

    private Classroom $outside;

    private Student $student;

    private Student $other;

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::create(['name' => 'QA Staff', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $this->room = Classroom::create(['name' => 'السابع', 'stage' => 'إعدادي', 'section' => 'أ', 'academic_year_id' => $year->id]);
        $this->outside = Classroom::create(['name' => 'الثاني ثانوي', 'stage' => 'ثانوي', 'section' => 'أدبي', 'academic_year_id' => $year->id]);
        $this->student = $this->makeStudent($this->room, 'QA-STAFF-IN');
        $this->other = $this->makeStudent($this->outside, 'QA-STAFF-OUT');
        $this->staff = User::factory()->create(['role' => 'staff', 'status' => 'active']);
        $this->role = StaffRole::create(['name' => 'QA Scoped Staff', 'active' => true]);
        foreach (['students.view', 'students.update', 'announcements.create', 'announcements.publish', 'activities.manage'] as $permission) {
            $this->role->permissions()->create(['permission' => $permission]);
        }
        StaffRoleAssignment::create(['user_id' => $this->staff->id, 'staff_role_id' => $this->role->id, 'active' => true, 'scope' => ['classroom_ids' => [$this->room->id]]]);
        $this->actingAs($this->staff);
    }

    private function makeStudent(Classroom $room, string $number): Student
    {
        return Student::create(['user_id' => User::factory()->create(['role' => 'student', 'status' => 'active'])->id, 'classroom_id' => $room->id, 'student_number' => $number, 'status' => 'active']);
    }

    public function test_scoped_staff_can_open_dashboard_and_only_see_assigned_students(): void
    {
        $this->get('/staff/dashboard')->assertOk();
        $this->get('/staff/students')->assertOk()->assertSee($this->student->user->name)->assertDontSee($this->other->user->name);
        $this->get('/staff/students?classroom_id='.$this->outside->id)->assertOk()->assertDontSee($this->other->user->name);
        $this->get('/staff/academic')->assertForbidden();
        $this->get('/staff/students/'.$this->other->id.'/edit')->assertForbidden();
    }

    public function test_staff_cannot_move_a_student_outside_scope_or_change_the_account_role(): void
    {
        $payload = ['name' => $this->student->user->name, 'email' => $this->student->user->email, 'student_number' => $this->student->student_number, 'classroom_id' => $this->outside->id, 'status' => 'active'];
        $this->put('/staff/students/'.$this->student->id, $payload)->assertForbidden();
        $this->assertSame($this->room->id, $this->student->fresh()->classroom_id);
        $payload['classroom_id'] = $this->room->id;
        $payload['role'] = 'admin';
        $payload['name'] = 'QA Student Updated';
        $this->put('/staff/students/'.$this->student->id, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('QA Student Updated', $this->student->user->fresh()->name);
        $this->assertSame('student', $this->student->user->fresh()->role);
        $this->post('/admin/users/'.$this->staff->id.'/roles', ['role_ids' => []])->assertForbidden();
    }

    public function test_class_scopes_block_school_wide_and_foreign_announcements(): void
    {
        $this->get('/staff/activities')->assertOk()->assertDontSee('<option value="">عام</option>', false);
        $this->get('/staff/announcements')->assertOk()->assertDontSee('<option value="">كل الصفوف</option>', false);
        $payload = ['title' => 'QA Scoped Announcement', 'content' => 'Test only'];
        $this->post('/staff/announcements', $payload)->assertForbidden();
        $this->post('/staff/announcements', $payload + ['classroom_id' => $this->outside->id])->assertForbidden();
        $this->post('/staff/announcements', $payload + ['classroom_id' => $this->room->id])->assertRedirect();
        $announcement = Announcement::sole();
        $this->assertSame('draft', $announcement->status);
        $this->patch('/staff/announcements/'.$announcement->id.'/publish')->assertRedirect();
        $this->post('/staff/announcements/'.$announcement->id.'/broadcast')->assertForbidden();
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_role_and_assignment_scopes_intersect_instead_of_widening_each_other(): void
    {
        $this->role->permissions()->where('permission', 'students.view')->update(['scope' => json_encode(['student_ids' => [$this->other->id]])]);
        $access = app(StaffAccess::class);
        $this->assertFalse($access->allows($this->staff, 'students.view', $this->room->id, $this->student->id));
        $this->assertFalse($access->allows($this->staff, 'students.view', $this->outside->id, $this->other->id));
        $this->assertSame([], $access->students($this->staff, 'students.view')->pluck('id')->all());
        $this->role->permissions()->where('permission', 'students.view')->update(['scope' => json_encode(['student_ids' => [$this->student->id]])]);
        $this->assertSame([$this->student->id], $access->students($this->staff, 'students.view')->pluck('id')->all());
    }

    public function test_deactivated_role_and_user_immediately_lose_access(): void
    {
        $this->role->update(['active' => false]);
        $this->get('/staff/students')->assertForbidden();
        $this->role->update(['active' => true]);
        $this->staff->staffRoleAssignments()->update(['active' => false]);
        $this->get('/staff/students')->assertForbidden();
        $this->staff->update(['status' => 'inactive']);
        $this->get('/staff/dashboard')->assertForbidden();
    }

    public function test_admin_can_assign_a_scoped_role_without_changing_the_staff_account_or_ids(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->post('/admin/users/'.$this->staff->id.'/roles', [
            'role_ids' => [$this->role->id], 'scope' => ['classroom_ids' => [$this->outside->id]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $access = app(StaffAccess::class);
        $this->assertSame('staff', $this->staff->fresh()->role);
        $this->assertSame([$this->other->id], $access->students($this->staff, 'students.view')->pluck('id')->all());
        $this->post('/admin/users/'.$admin->id.'/roles', ['role_ids' => []])->assertUnprocessable();
    }

    public function test_students_cannot_enter_the_staff_portal(): void
    {
        $this->actingAs($this->student->user)->get('/staff/dashboard')->assertForbidden();
        $this->post('/staff/activities', ['title' => 'Unauthorized', 'activity_date' => now()->toDateString()])->assertForbidden();
        $this->assertDatabaseCount('school_activities', 0);
    }

    public function test_scoped_activity_creation_and_status_updates_preserve_foreign_records(): void
    {
        $this->post('/staff/activities', ['title' => 'QA Activity', 'classroom_id' => $this->room->id, 'activity_date' => today()->toDateString()])
            ->assertRedirect()->assertSessionHasNoErrors();
        $activity = SchoolActivity::sole();
        $this->assertSame('scheduled', $activity->status);
        $this->patch('/staff/activities/'.$activity->id, ['status' => 'completed'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', $activity->fresh()->status);
        $this->patch('/staff/activities/'.$activity->id, ['status' => 'invalid'])->assertSessionHasErrors('status');
        $foreign = SchoolActivity::create(['title' => 'Foreign Activity', 'classroom_id' => $this->outside->id, 'activity_date' => today(), 'status' => 'scheduled', 'created_by' => $this->staff->id]);
        $this->patch('/staff/activities/'.$foreign->id, ['status' => 'cancelled'])->assertForbidden();
        $this->assertSame('scheduled', $foreign->fresh()->status);
        $this->get('/staff/activities')->assertOk()->assertSee('QA Activity')->assertDontSee('Foreign Activity');
    }

    public function test_scoped_guardian_calls_notify_only_the_linked_family_and_reject_foreign_students(): void
    {
        $this->role->permissions()->create(['permission' => 'guardian-calls.manage']);
        $parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $parent->id, 'status' => 'active', 'relationship' => 'father']);
        $guardian->students()->attach($this->student);
        $payload = ['reason' => 'QA Family Meeting', 'guardian_visible_details' => 'QA details'];
        $this->post('/staff/calls', $payload + ['student_id' => $this->other->id])->assertNotFound();
        $this->assertDatabaseCount('guardian_calls', 0);
        $this->post('/staff/calls', $payload + ['student_id' => $this->student->id])->assertRedirect()->assertSessionHasNoErrors();
        $call = GuardianCall::sole();
        $this->assertSame($this->student->id, $call->student_id);
        $this->assertSame(1, $parent->notifications()->count());
        $this->patch('/staff/calls/'.$call->id, ['status' => 'completed'])->assertRedirect();
        $this->assertNotNull($call->fresh()->resolved_at);
        $this->actingAs($parent)->get('/parent/guardian-calls?student='.$this->student->id)->assertOk()->assertSee('QA Family Meeting');
    }

    public function test_shared_resources_are_visible_through_an_allowed_target_without_exposing_other_classes(): void
    {
        foreach (['assignments.view', 'teacher-monitoring.view'] as $permission) {
            $this->role->permissions()->create(['permission' => $permission]);
        }
        $subject = Subject::create(['name' => 'QA Subject', 'code' => 'QA-STAFF-SUB', 'stage' => 'إعدادي', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        $teacher->classrooms()->attach($this->room->id, ['subject_id' => $subject->id]);
        $attributes = ['teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'classroom_id' => $this->outside->id, 'status' => 'published'];
        $assignment = Assignment::create($attributes + ['title' => 'QA Shared Homework']);
        $assignment->classrooms()->attach([$this->outside->id, $this->room->id]);
        Assignment::create($attributes + ['title' => 'QA Outside Homework']);
        $lesson = Lesson::create($attributes + ['title' => 'QA Shared Lesson', 'published_at' => now()]);
        $lesson->classrooms()->attach([$this->outside->id, $this->room->id]);
        Lesson::create($attributes + ['title' => 'QA Outside Lesson']);
        $this->get('/staff/assignments')->assertOk()->assertSee('QA Shared Homework')->assertDontSee('QA Outside Homework')->assertDontSee('الثاني ثانوي');
        $this->get('/staff/teachers')->assertOk()->assertViewHas('lessons', fn ($counts) => $counts[$teacher->id] === 1)
            ->assertViewHas('assignments', fn ($counts) => $counts[$teacher->id] === 1);
        $this->role->permissions()->whereIn('permission', ['assignments.view', 'teacher-monitoring.view'])->update(['scope' => json_encode(['classroom_ids' => [$this->outside->id]])]);
        $this->assertSame(0, app(StaffAccess::class)->learningResources(Assignment::query(), $this->staff, 'assignments.view')->count());
    }
}
