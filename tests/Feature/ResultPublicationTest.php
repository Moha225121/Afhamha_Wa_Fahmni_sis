<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Exam;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResultPublicationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $parent;

    private Student $student;

    private Subject $subject;

    private int $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $this->parent->id, 'status' => 'active']);
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-01']);
        $classroom = Classroom::create(['name' => 'الأول', 'stage' => 'أساسي', 'section' => 'أ', 'academic_year_id' => $year->id]);
        $user = User::factory()->create(['name' => 'طالب النتائج', 'role' => 'student', 'status' => 'active']);
        $this->student = Student::create(['user_id' => $user->id, 'student_number' => 'S-RES', 'classroom_id' => $classroom->id, 'status' => 'active']);
        $guardian->students()->attach($this->student);
        $this->subject = Subject::create(['name' => 'رياضيات خاصة', 'code' => 'RES-M', 'stage' => 'أساسي', 'status' => 'active']);
        $this->actingAs($this->admin)->post(route('admin.results.store'), ['name' => 'الفترة السرية', 'academic_year_id' => $year->id, 'term' => 'الأول', 'exam_type' => 'نهائي', 'classroom_id' => $classroom->id])->assertSessionHasNoErrors();
        $this->period = DB::table('exam_periods')->value('id');
    }

    private function mark(): void
    {
        $this->actingAs($this->admin)->post(route('admin.results.mark', $this->period), ['student_id' => $this->student->id, 'subject_id' => $this->subject->id, 'score' => '87', 'maximum_score' => '100', 'notes' => 'أحسنت في هذه الفترة'])->assertSessionHasNoErrors();
    }

    private function transition(string $status): void
    {
        $this->actingAs($this->admin)->patch(route('admin.results.status', $this->period), ['status' => $status])->assertSessionHasNoErrors();
    }

    public function test_result_is_hidden_until_published_then_notified_and_can_be_hidden_again(): void
    {
        $this->mark();
        foreach (['draft', 'under_review', 'approved'] as $status) {
            if ($status !== 'draft') {
                $this->transition($status);
            }
            $this->actingAs($this->student->user)->get('/student/results')->assertOk()->assertDontSee('الفترة السرية')->assertDontSee('أحسنت في هذه الفترة');
            $this->actingAs($this->parent)->get('/parent/results')->assertOk()->assertDontSee('الفترة السرية');
        }
        $this->transition('published');
        $this->actingAs($this->student->user)->get('/student/results')->assertOk()->assertSee('الفترة السرية')->assertSee('87%')->assertSee('ناجح');
        $this->actingAs($this->parent)->get('/parent/results')->assertOk()->assertSee('الفترة السرية');
        $this->assertSame(1, $this->parent->notifications()->count());
        $this->assertSame(1, $this->student->user->notifications()->count());
        $this->transition('published');
        $this->assertSame(1, $this->parent->notifications()->count());
        $this->transition('hidden');
        $this->actingAs($this->student->user)->get('/student/dashboard')->assertOk()->assertDontSee('الفترة السرية');
        $this->getJson(route('student.notifications.summary'))->assertOk()->assertJsonPath('unread_count', 0)->assertJsonCount(0, 'notifications');
        $this->get('/student/results')->assertDontSee('الفترة السرية');
        $this->actingAs($this->parent)->get('/parent/results')->assertDontSee('الفترة السرية');
        $this->getJson(route('parent.notifications.summary'))->assertOk()->assertJsonPath('unread_count', 0)->assertJsonCount(0, 'notifications');
        $this->assertDatabaseHas('audit_logs', ['action' => 'results_hidden']);
    }

    public function test_no_bypass_from_draft_and_published_marks_are_immutable(): void
    {
        $this->mark();
        $this->actingAs($this->admin)->patch(route('admin.results.status', $this->period), ['status' => 'published'])->assertSessionHasErrors('status');
        foreach (['under_review', 'approved', 'published'] as $status) {
            $this->transition($status);
        }
        $this->post(route('admin.results.mark', $this->period), ['student_id' => $this->student->id, 'subject_id' => $this->subject->id, 'score' => '1', 'maximum_score' => '100'])->assertSessionHasErrors('period');
        $this->assertDatabaseHas('result_marks', ['score' => 87]);
        $this->actingAs($this->student->user)->patch(route('admin.results.status', $this->period), ['status' => 'hidden'])->assertForbidden();
    }

    public function test_scheduled_results_wait_until_due_and_unlinked_parent_cannot_access(): void
    {
        $this->mark();
        $due = now()->addDay();
        DB::table('exam_periods')->where('id', $this->period)->update(['scheduled_at' => $due]);
        $this->transition('under_review');
        $this->transition('approved');
        $this->patch(route('admin.results.status', $this->period), ['status' => 'published'])->assertSessionHasErrors('status');
        $this->artisan('results:publish-due')->assertSuccessful();
        $this->assertDatabaseHas('exam_periods', ['id' => $this->period, 'status' => 'approved']);
        $this->travelTo($due->copy()->addMinute());
        $this->artisan('results:publish-due')->assertSuccessful();
        $this->assertDatabaseHas('exam_periods', ['id' => $this->period, 'status' => 'published']);
        $outsider = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        Guardian::create(['user_id' => $outsider->id, 'status' => 'active']);
        $this->actingAs($outsider)->get('/parent/results?student='.$this->student->id)->assertNotFound();
    }

    public function test_sheets_and_new_electronic_attempts_do_not_leak_unapproved_grades(): void
    {
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher'])->id, 'status' => 'active']);
        DB::table('teacher_assignments')->insert(['teacher_id' => $teacher->id, 'classroom_id' => $this->student->classroom_id, 'subject_id' => $this->subject->id]);
        DB::table('grade_sheets')->insert(['teacher_id' => $teacher->id, 'classroom_id' => $this->student->classroom_id, 'scores' => json_encode([$this->student->id => 99])]);
        $exam = Exam::create(['title' => 'اختبار غير معتمد', 'subject_id' => $this->subject->id, 'teacher_id' => $teacher->id, 'classroom_id' => $this->student->classroom_id, 'starts_at' => now()->subHour(), 'duration_minutes' => 30, 'total_score' => 100, 'status' => 'published']);
        DB::table('grades')->insert(['exam_id' => $exam->id, 'student_id' => $this->student->id, 'score' => 99, 'published_at' => now()]);
        $this->actingAs($this->student->user)->get('/student/dashboard')->assertOk()->assertDontSee('99%');
        $this->get('/student/results')->assertOk()->assertDontSee('اختبار غير معتمد');
        $this->actingAs($this->parent)->get('/parent/results')->assertOk()->assertDontSee('اختبار غير معتمد');
        $this->get('/parent/exams')->assertOk()->assertDontSee('99 /');
    }

    public function test_existing_teacher_sheet_can_be_imported_with_subject_authorization(): void
    {
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher'])->id, 'status' => 'active']);
        DB::table('teacher_assignments')->insert(['teacher_id' => $teacher->id, 'classroom_id' => $this->student->classroom_id, 'subject_id' => $this->subject->id]);
        $sheet = DB::table('grade_sheets')->insertGetId(['teacher_id' => $teacher->id, 'classroom_id' => $this->student->classroom_id, 'scores' => json_encode([$this->student->id => 79.5])]);
        $this->get(route('admin.results.show', $this->period))->assertOk()->assertSee('استيراد كشف المعلم');
        $this->post(route('admin.results.import-sheet', $this->period), ['sheet_id' => $sheet, 'subject_id' => $this->subject->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('result_marks', ['student_id' => $this->student->id, 'subject_id' => $this->subject->id, 'score' => 79.5]);
        $unassigned = Subject::create(['name' => 'غير مسندة', 'code' => 'UNASSIGNED', 'stage' => 'أساسي']);
        $this->post(route('admin.results.import-sheet', $this->period), ['sheet_id' => $sheet, 'subject_id' => $unassigned->id])->assertUnprocessable();
        $this->actingAs($this->parent)->get('/parent/results')->assertDontSee('79.5');
    }

    public function test_round_two_empty_draft_explains_steps_and_cannot_be_reviewed(): void
    {
        $this->get(route('admin.results.show', $this->period))->assertOk()
            ->assertSeeText('الخطوة التالية: أدخل الدرجات أو استوردها')
            ->assertSeeText('إنشاء الفترة لا ينشر النتائج');
        $this->patch(route('admin.results.status', $this->period), ['status' => 'under_review'])->assertSessionHasErrors('status');
        $this->assertDatabaseHas('exam_periods', ['id' => $this->period, 'status' => 'draft']);
    }

    public function test_round_two_repeated_marks_preserve_distinct_subjects_and_periods_and_draft_removal_is_audited(): void
    {
        $this->mark();
        $this->mark();
        $second = Subject::create(['name' => 'علوم', 'code' => 'R2-SCI', 'stage' => 'أساسي', 'status' => 'active']);
        $this->post(route('admin.results.mark', $this->period), ['student_id' => $this->student->id, 'subject_id' => $second->id, 'score' => 9, 'maximum_score' => 10])->assertSessionHasNoErrors();
        $other = DB::table('exam_periods')->insertGetId(['name' => 'اختبار شهري', 'academic_year_id' => $this->student->classroom->academic_year_id, 'term' => 'الأول', 'exam_type' => 'شهري', 'status' => 'draft', 'created_by' => $this->admin->id]);
        $this->post(route('admin.results.mark', $other), ['student_id' => $this->student->id, 'subject_id' => $this->subject->id, 'score' => 7, 'maximum_score' => 10])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('result_marks', 3);
        $mark = DB::table('result_marks')->where('exam_period_id', $this->period)->where('subject_id', $this->subject->id)->first();
        $this->get(route('admin.results.show', $this->period))->assertOk()->assertSeeText('تعديل الدرجة')->assertSeeText('حذف درجة المسودة');
        $this->actingAs($this->parent)->delete(route('admin.results.mark.destroy', [$this->period, $mark->id]))->assertForbidden();
        $this->actingAs($this->admin)->delete(route('admin.results.mark.destroy', [$other, $mark->id]))->assertNotFound();
        $this->delete(route('admin.results.mark.destroy', [$this->period, $mark->id]))->assertRedirect()->assertSessionHasNoErrors();
        $this->delete(route('admin.results.mark.destroy', [$this->period, $mark->id]))->assertNotFound();
        $this->assertDatabaseCount('result_marks', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'result_mark_removed']);
        $this->transition('under_review');
        $remaining = DB::table('result_marks')->where('exam_period_id', $this->period)->first();
        $this->delete(route('admin.results.mark.destroy', [$this->period, $remaining->id]))->assertSessionHasErrors('period');
        $this->assertDatabaseCount('result_marks', 2);
    }

    public function test_round_two_empty_exam_import_is_reported_and_does_not_claim_success_or_link_exam(): void
    {
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher'])->id, 'status' => 'active']);
        $exam = Exam::create(['title' => 'لم يصحح', 'subject_id' => $this->subject->id, 'teacher_id' => $teacher->id, 'classroom_id' => $this->student->classroom_id, 'starts_at' => now()->subHour(), 'duration_minutes' => 30, 'total_score' => 100, 'status' => 'published']);
        $this->post(route('admin.results.import', $this->period), ['exam_ids' => [$exam->id]])->assertSessionHasErrors('exam_ids');
        $this->assertNull($exam->fresh()->exam_period_id);
        $this->assertDatabaseCount('result_marks', 0);
    }

    public function test_round_two_repeated_exam_import_updates_one_mark_and_preserves_other_subjects(): void
    {
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher'])->id, 'status' => 'active']);
        $exam = Exam::create(['title' => 'اختبار مصحح', 'subject_id' => $this->subject->id, 'teacher_id' => $teacher->id, 'classroom_id' => $this->student->classroom_id, 'starts_at' => now()->subHour(), 'duration_minutes' => 30, 'total_score' => 100, 'status' => 'published']);
        DB::table('grades')->insert(['exam_id' => $exam->id, 'student_id' => $this->student->id, 'score' => 81]);
        foreach (range(1, 2) as $_) $this->post(route('admin.results.import', $this->period), ['exam_ids' => [$exam->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('result_marks', 1);
        $this->assertDatabaseHas('result_marks', ['exam_period_id' => $this->period, 'student_id' => $this->student->id, 'subject_id' => $this->subject->id, 'score' => 81]);
        DB::table('grades')->where('exam_id', $exam->id)->update(['score' => 84]);
        $this->post(route('admin.results.import', $this->period), ['exam_ids' => [$exam->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('result_marks', 1);
        $this->assertDatabaseHas('result_marks', ['score' => 84]);
    }

    public function test_round_two_exam_cannot_be_imported_into_another_period_and_batch_is_atomic(): void
    {
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher'])->id, 'status' => 'active']);
        $exams = collect(['مرتبط', 'جديد'])->map(fn ($title) => Exam::create(['title' => $title, 'subject_id' => $this->subject->id, 'teacher_id' => $teacher->id, 'classroom_id' => $this->student->classroom_id, 'starts_at' => now()->subHour(), 'duration_minutes' => 30, 'total_score' => 100, 'status' => 'published']));
        foreach ($exams as $exam) DB::table('grades')->insert(['exam_id' => $exam->id, 'student_id' => $this->student->id, 'score' => 81]);
        $linked = $exams->first();
        $new = $exams->last();
        $new->update(['subject_id' => Subject::create(['name' => 'علوم', 'code' => 'R2-ATOMIC', 'stage' => 'أساسي', 'status' => 'active'])->id]);
        $this->post(route('admin.results.import', $this->period), ['exam_ids' => [$linked->id]])->assertSessionHasNoErrors();
        $other = DB::table('exam_periods')->insertGetId(['name' => 'فترة مستقلة', 'academic_year_id' => $this->student->classroom->academic_year_id, 'term' => 'الأول', 'exam_type' => 'شهري', 'status' => 'draft', 'created_by' => $this->admin->id]);

        $this->post(route('admin.results.import', $other), ['exam_ids' => [$new->id, $linked->id]])->assertSessionHasErrors('exams');

        $this->assertSame($this->period, (int) $linked->fresh()->exam_period_id);
        $this->assertNull($new->fresh()->exam_period_id);
        $this->assertDatabaseCount('result_marks', 1);
        $this->assertDatabaseMissing('result_marks', ['exam_period_id' => $other]);
        $this->assertDatabaseHas('result_marks', ['exam_period_id' => $this->period, 'student_id' => $this->student->id, 'score' => 81]);
        $this->assertDatabaseCount('grades', 2);
    }

    public function test_round_two_corrected_republication_does_not_restore_withdrawn_student_result(): void
    {
        $this->mark();
        foreach (['under_review', 'approved', 'published', 'hidden', 'draft'] as $status) $this->transition($status);
        $mark = DB::table('result_marks')->where('exam_period_id', $this->period)->first();
        $this->delete(route('admin.results.mark.destroy', [$this->period, $mark->id]))->assertSessionHasNoErrors();
        $otherUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $otherStudent = Student::create(['user_id' => $otherUser->id, 'student_number' => 'R2-OTHER', 'classroom_id' => $this->student->classroom_id, 'status' => 'active']);
        $this->post(route('admin.results.mark', $this->period), ['student_id' => $otherStudent->id, 'subject_id' => $this->subject->id, 'score' => 9, 'maximum_score' => 10])->assertSessionHasNoErrors();
        foreach (['under_review', 'approved', 'published'] as $status) $this->transition($status);
        $this->assertDatabaseMissing('result_publications', ['exam_period_id' => $this->period, 'student_id' => $this->student->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student_result_withdrawn']);
        $this->actingAs($this->student->user)->get('/student/results')->assertOk()->assertDontSee('الفترة السرية');
        $this->actingAs($this->parent)->get('/parent/results')->assertOk()->assertDontSee('الفترة السرية');
        $this->actingAs($otherUser)->get('/student/results')->assertOk()->assertSee('الفترة السرية');
    }

    public function test_round_two_explicit_utc_schedule_stores_and_publishes_at_the_exact_boundary(): void
    {
        config(['app.display_timezone' => 'Africa/Tripoli']);
        $this->get(route('admin.results.index'))->assertOk()->assertSeeText('موعد النشر (UTC، اختياري)');
        $this->post(route('admin.results.store'), ['name' => 'R2 boundary', 'academic_year_id' => $this->student->classroom->academic_year_id, 'term' => 'الأول', 'exam_type' => 'شهري', 'classroom_id' => $this->student->classroom_id, 'scheduled_at' => '2027-01-01T00:30'])->assertSessionHasNoErrors();
        $this->period = DB::table('exam_periods')->where('name', 'R2 boundary')->value('id');
        $this->assertDatabaseHas('exam_periods', ['id' => $this->period, 'scheduled_at' => '2027-01-01 00:30:00']);
        $this->mark();
        $this->transition('under_review'); $this->transition('approved');
        $this->travelTo(\Illuminate\Support\Carbon::parse('2027-01-01 00:29:59', 'UTC'));
        $this->artisan('results:publish-due')->assertSuccessful();
        $this->assertDatabaseHas('exam_periods', ['id' => $this->period, 'status' => 'approved']);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2027-01-01 00:30:00', 'UTC'));
        $this->artisan('results:publish-due')->assertSuccessful();
        $this->artisan('results:publish-due')->assertSuccessful();
        $this->assertDatabaseHas('exam_periods', ['id' => $this->period, 'status' => 'published']);
        $this->assertSame(1, $this->parent->notifications()->count());
    }

}
