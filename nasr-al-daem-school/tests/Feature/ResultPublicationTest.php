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
        $this->get('/student/results')->assertDontSee('الفترة السرية');
        $this->actingAs($this->parent)->get('/parent/results')->assertDontSee('الفترة السرية');
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
}
