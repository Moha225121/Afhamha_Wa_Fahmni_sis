<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Exam;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\SchoolAccountService;
use App\Services\TeacherAssignmentGroups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NisbiyaUpgradePreservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_settings_preview_and_save_preserve_the_existing_student_finance_option_and_accounts(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $accounts = app(SchoolAccountService::class);
        $student = $accounts->create('student', ['name' => 'QA Preservation', 'email' => 'preservation@example.test', 'password' => 'Synthetic-test-password', 'status' => 'active'], ['status' => 'active']);
        $beforeUser = $student->user->getAttributes();
        $beforeStudent = $student->fresh()->getAttributes();
        foreach ([true, false] as $visible) {
            $settings = $accounts->settings();
            $settings['student_finance_visible'] = $visible;
            DB::table('account_number_settings')->where('id', 1)->update(['configuration' => json_encode($settings)]);
            $this->get(route('admin.accounts.index'))->assertOk()->assertSee('name="student_finance_visible"', false);
            $this->post(route('admin.accounts.preview'), $settings)->assertOk()->assertSessionHasNoErrors();
            $this->assertSame($visible, (bool) session('account_numbering_preview.settings.student_finance_visible'));
            $this->put(route('admin.accounts.update'), ['revision' => session('account_numbering_preview.revision')])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($visible, (bool) $accounts->settings()['student_finance_visible']);
        }
        $this->assertSame($beforeUser, $student->user->fresh()->getAttributes());
        $this->assertSame($beforeStudent, $student->fresh()->getAttributes());
    }

    public function test_a_stale_settings_form_cannot_silently_remove_student_finance_visibility(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $accounts = app(SchoolAccountService::class);
        $settings = $accounts->settings();
        $settings['student_finance_visible'] = true;
        DB::table('account_number_settings')->where('id', 1)->update(['configuration' => json_encode($settings)]);
        unset($settings['student_finance_visible']);
        $this->post(route('admin.accounts.preview'), $settings)->assertSessionHasErrors('student_finance_visible');
        $this->assertTrue((bool) $accounts->settings()['student_finance_visible']);
    }

    public function test_admin_can_create_and_update_teaching_assignments_in_both_secondary_tracks_without_mixing_them(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $year = AcademicYear::create(['name' => 'QA Tracks', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $literary = Classroom::create(['name' => 'الثاني ثانوي', 'section' => 'أدبي', 'stage' => 'ثانوي', 'academic_year_id' => $year->id]);
        $science = Classroom::create(['name' => 'الثاني ثانوي', 'section' => 'علمي', 'stage' => 'ثانوي', 'academic_year_id' => $year->id]);
        $subject = Subject::create(['name' => 'QA Language', 'code' => 'QA-TRACKS', 'stage' => 'ثانوي', 'status' => 'active']);
        foreach ([$literary, $science] as $room) {
            $room->subjects()->attach($subject);
        }
        $payload = ['name' => 'QA Teacher', 'email' => 'tracks@example.test', 'status' => 'active', 'assignments' => [['classroom_id' => (string) $literary->id, 'subject_id' => $subject->id]]];
        $this->post('/admin/teachers', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $teacher = Teacher::sole();
        $this->assertDatabaseHas('teacher_assignments', ['teacher_id' => $teacher->id, 'classroom_id' => $literary->id, 'subject_id' => $subject->id]);
        $payload['assignments'][] = ['classroom_id' => (string) $science->id, 'subject_id' => $subject->id];
        $this->put('/admin/teachers/'.$teacher->id, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $teacher->classrooms()->count());
        $this->assertSame([], TeacherAssignmentGroups::groups(collect([$literary, $science])));
        $unlinked = Subject::create(['name' => 'QA Unlinked', 'code' => 'QA-NOT-TRACK', 'stage' => 'ثانوي', 'status' => 'active']);
        $payload['assignments'] = [['classroom_id' => (string) $literary->id, 'subject_id' => $unlinked->id]];
        $this->put('/admin/teachers/'.$teacher->id, $payload)->assertSessionHasErrors('assignments.0.subject_id');
        $this->assertSame(2, $teacher->classrooms()->count());
    }

    public function test_deleting_a_used_subject_cannot_cascade_into_exams_grades_or_curriculum_links(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $year = AcademicYear::create(['name' => 'QA Subject', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $room = Classroom::create(['name' => 'السابع', 'section' => 'أ', 'stage' => 'إعدادي', 'academic_year_id' => $year->id]);
        $subject = Subject::create(['name' => 'QA Preserved', 'code' => 'QA-PRESERVE', 'stage' => 'إعدادي', 'status' => 'active']);
        $room->subjects()->attach($subject);
        $this->delete('/admin/subjects/'.$subject->id)->assertSessionHasErrors('subject');
        $this->assertDatabaseHas('classroom_subject', ['classroom_id' => $room->id, 'subject_id' => $subject->id]);
        // An historical exam alone must protect its subject, even when the curriculum link is absent.
        $room->subjects()->detach($subject);
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher'])->id, 'status' => 'active']);
        $exam = Exam::create(['title' => 'QA Historical Exam', 'classroom_id' => $room->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id, 'starts_at' => now(), 'duration_minutes' => 30, 'total_score' => 100, 'status' => 'published']);
        $student = app(SchoolAccountService::class)->create('student', ['name' => 'QA Grade', 'email' => 'grade-preserve@example.test', 'password' => 'Synthetic-test-password'], ['classroom_id' => $room->id, 'status' => 'active']);
        $gradeId = DB::table('grades')->insertGetId(['exam_id' => $exam->id, 'student_id' => $student->id, 'score' => 75, 'created_at' => now(), 'updated_at' => now()]);
        $this->delete('/admin/subjects/'.$subject->id)->assertSessionHasErrors('subject');
        $this->assertNotNull($subject->fresh());
        $this->assertDatabaseHas('grades', ['id' => $gradeId, 'score' => 75]);
        $this->assertNotNull($exam->fresh());
        $unused = Subject::create(['name' => 'QA Empty', 'code' => 'QA-EMPTY', 'stage' => 'إعدادي', 'status' => 'active']);
        $this->delete('/admin/subjects/'.$unused->id)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('subjects', ['id' => $unused->id]);
    }
}
