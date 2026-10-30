<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Services\ParentStudentSession;
use App\Services\TeacherAssignmentGroups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NisbiyaCompatibilityPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_unapproved_grade_and_impersonation_policies_are_disabled_by_default(): void
    {
        foreach (['teacher_sheet_visibility', 'teacher_sheet_notifications', 'parent_student_session', 'external_teacher_reports', 'shared_assignment_grading', 'public_student_card_verification'] as $policy) {
            $this->assertFalse(config('nasbia.'.$policy));
        }
    }

    public function test_parent_cannot_switch_to_its_linked_secondary_student_even_if_grade_name_looks_primary(): void
    {
        $year = AcademicYear::create(['name' => 'QA-Policy', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $room = Classroom::create(['name' => 'الصف الأول', 'stage' => 'ثانوي', 'section' => 'أدبي', 'academic_year_id' => $year->id]);
        $studentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $student = Student::create(['user_id' => $studentUser->id, 'classroom_id' => $room->id, 'student_number' => 'QA-POLICY', 'status' => 'active']);
        $parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $parent->id, 'status' => 'active']);
        $guardian->students()->attach($student);
        $this->assertFalse(app(ParentStudentSession::class)->canOpen($student));
        $this->actingAs($parent)->post(route('parent.children.student-session', $student))->assertNotFound();
        $this->assertAuthenticatedAs($parent);
        $this->assertDatabaseHas('students', ['id' => $student->id, 'user_id' => $studentUser->id, 'classroom_id' => $room->id]);
    }

    public function test_grouping_keeps_scientific_literary_stages_and_years_separate(): void
    {
        $year = AcademicYear::create(['name' => 'QA-Groups', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        foreach (['علمي', 'أدبي'] as $track) {
            Classroom::create(['name' => 'الثاني ثانوي', 'stage' => 'ثانوي', 'section' => $track, 'academic_year_id' => $year->id]);
        }
        $this->assertSame([], TeacherAssignmentGroups::groups(Classroom::all()));
        $this->assertDatabaseCount('classrooms', 2);
    }
}
