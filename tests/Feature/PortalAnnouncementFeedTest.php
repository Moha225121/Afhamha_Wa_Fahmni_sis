<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Announcement;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\ParentNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortalAnnouncementFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_targeted_ads_appear_only_in_their_portals_and_authorized_classrooms(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $classroom = Classroom::create(['name' => 'Grade 6', 'section' => 'A', 'stage' => 'Basic', 'academic_year_id' => $year->id]);
        $otherClassroom = Classroom::create(['name' => 'Grade 7', 'section' => 'A', 'stage' => 'Basic', 'academic_year_id' => $year->id]);
        $subject = Subject::create(['name' => 'Science', 'code' => 'SCI-ADS', 'stage' => 'Basic', 'status' => 'active']);
        $classroom->subjects()->attach($subject);

        $studentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $student = Student::create(['user_id' => $studentUser->id, 'student_number' => 'S-ADS', 'classroom_id' => $classroom->id, 'status' => 'active']);
        $parentUser = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $parentUser->id, 'status' => 'active']);
        $guardian->students()->attach($student);

        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'status' => 'active']);
        DB::table('teacher_assignments')->insert(['teacher_id' => $teacher->id, 'classroom_id' => $classroom->id, 'subject_id' => $subject->id]);
        $supervisor = User::factory()->create(['role' => 'supervisor', 'status' => 'active']);
        $supervisor->supervisedClassrooms()->attach($classroom);
        $staff = User::factory()->create(['role' => 'staff', 'status' => 'active']);

        $this->announcement($admin, 'All Portal Notice', 'all');
        $this->announcement($admin, 'Student Notice', 'students');
        $this->announcement($admin, 'Parent Notice', 'parents');
        $this->announcement($admin, 'Teacher Notice', 'teachers');
        $this->announcement($admin, 'Supervisor Notice', 'supervisors');
        $this->announcement($admin, 'Staff Notice', 'staff');
        $this->announcement($admin, 'Class Notice', 'classroom', $classroom->id);
        $this->announcement($admin, 'Other Class Notice', 'classroom', $otherClassroom->id);

        $this->actingAs($studentUser)->get(route('student.ads.index'))
            ->assertOk()->assertSeeText('All Portal Notice')->assertSeeText('Student Notice')
            ->assertSeeText('Class Notice')->assertDontSeeText('Other Class Notice')
            ->assertDontSeeText('Teacher Notice');
        $this->actingAs($parentUser)->get(route('parent.ads.index'))
            ->assertOk()->assertSeeText('All Portal Notice')->assertSeeText('Parent Notice')
            ->assertSeeText('Class Notice')->assertDontSeeText('Other Class Notice')
            ->assertDontSeeText('Teacher Notice');
        $this->actingAs($teacherUser)->get(route('teacher.ads.index'))
            ->assertOk()->assertSeeText('All Portal Notice')->assertSeeText('Teacher Notice')
            ->assertSeeText('Class Notice')->assertDontSeeText('Other Class Notice')
            ->assertDontSeeText('Student Notice');
        $this->actingAs($supervisor)->get(route('supervisor.ads.index'))
            ->assertOk()->assertSeeText('All Portal Notice')->assertSeeText('Supervisor Notice')
            ->assertSeeText('Class Notice')->assertDontSeeText('Other Class Notice');
        $this->actingAs($staff)->get(route('staff.ads.index'))
            ->assertOk()->assertSeeText('All Portal Notice')->assertSeeText('Staff Notice')
            ->assertDontSeeText('Class Notice')->assertDontSeeText('Other Class Notice');
    }

    public function test_teacher_only_ad_does_not_send_parent_notification(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        Guardian::create(['user_id' => $parent->id, 'status' => 'active']);
        $teacherNotice = $this->announcement($admin, 'Staff Notice Never Pushes to Parents', 'teachers');

        app(ParentNotificationService::class)->sendAnnouncement($teacherNotice);

        $this->assertSame(0, $parent->notifications()->count());
    }

    public function test_financial_officer_uses_staff_ad_audience(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $officer = User::factory()->create(['role' => 'financial_officer', 'status' => 'active']);
        $this->announcement($admin, 'All Portal Notice', 'all');
        $this->announcement($admin, 'Staff Notice', 'staff');
        $this->announcement($admin, 'Teacher Notice', 'teachers');

        $this->actingAs($officer)
            ->get(route('staff.ads.index'))
            ->assertOk()
            ->assertSeeText('All Portal Notice')
            ->assertSeeText('Staff Notice')
            ->assertDontSeeText('Teacher Notice');
    }

    private function announcement(User $admin, string $title, string $audience, ?int $classroomId = null): Announcement
    {
        return Announcement::create([
            'title' => $title,
            'content' => $title.' content',
            'audience' => $audience,
            'classroom_id' => $classroomId,
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'created_by' => $admin->id,
        ]);
    }
}
