<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupervisorAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    private User $teacher;

    private User $parent;

    private Classroom $classroom;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::create(['name' => '2026', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-01', 'is_current' => true]);
        $this->classroom = Classroom::create(['name' => 'الأول', 'stage' => 'أساسي', 'section' => 'أ', 'academic_year_id' => $year->id]);
        $this->supervisor = User::factory()->create(['role' => 'supervisor', 'status' => 'active']);
        $this->supervisor->supervisedClassrooms()->attach($this->classroom);
        $this->teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $this->parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $studentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->student = Student::create(['user_id' => $studentUser->id, 'student_number' => 'S-1', 'classroom_id' => $this->classroom->id, 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $this->parent->id, 'status' => 'active']);
        $guardian->students()->attach($this->student);
    }

    public function test_supervisor_can_create_and_update_one_daily_record(): void
    {
        $p = ['date' => '2026-09-10', 'classroom_id' => $this->classroom->id, 'records' => [$this->student->id => ['status' => 'absent']]];
        $this->actingAs($this->supervisor)->post('/supervisor/attendance', $p)->assertSessionHasNoErrors();
        $p['records'][$this->student->id] = ['status' => 'excused_absence', 'excuse_reason' => 'تقرير'];
        $this->post('/supervisor/attendance', $p)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseHas('attendance_records', ['student_id' => $this->student->id, 'status' => 'excused_absence']);
    }

    public function test_supervisor_attendance_starts_present_with_an_absence_checkbox_and_offers_bulk_absence_undo(): void
    {
        $this->actingAs($this->supervisor)->get('/supervisor/attendance')->assertOk()->assertSee('data-student-check', false)->assertSee('data-student-search', false)->assertSee('data-mark-all-absent', false)->assertSee('data-undo-mark-all-absent', false)->assertSee('value="present" data-status-value', false)->assertSee('تحديد الفصل كاملًا كغائب')->assertSee('تراجع عن تحديد الكل كغائب')->assertDontSee('وقت الوصول')->assertDontSee('متأخر')->assertDontSee('غياب بعذر');
    }

    public function test_supervisor_can_customize_attendance_report_by_period_and_student(): void
    {
        Attendance::create(['student_id' => $this->student->id, 'classroom_id' => $this->classroom->id, 'date' => '2026-09-29', 'status' => 'absent', 'recorded_by' => $this->supervisor->id]);
        Attendance::create(['student_id' => $this->student->id, 'classroom_id' => $this->classroom->id, 'date' => '2026-09-30', 'status' => 'present', 'recorded_by' => $this->supervisor->id]);
        $this->actingAs($this->supervisor)->get('/supervisor/reports?period=weekly&date=2026-09-29&student_id='.$this->student->id)->assertOk()->assertSee('تقارير الحضور')->assertSee('نسبة الحضور')->assertSee('50.0%')->assertSee('أسبوعي');
    }

    public function test_teacher_and_parent_cannot_write_attendance(): void
    {
        foreach ([$this->teacher, $this->parent] as $u) {
            $this->actingAs($u)->post('/supervisor/attendance', [])->assertForbidden();
        }
    }

    public function test_supervisor_cannot_access_unassigned_student(): void
    {
        $other = Classroom::create(['name' => 'الثاني', 'stage' => 'أساسي', 'section' => 'ب', 'academic_year_id' => $this->classroom->academic_year_id]);
        $s = Student::create(['user_id' => User::factory()->create(['role' => 'student'])->id, 'student_number' => 'S-2', 'classroom_id' => $other->id]);
        $this->actingAs($this->supervisor)->get('/supervisor/students/'.$s->id)->assertForbidden();
    }

    public function test_guardian_sees_only_visible_notes(): void
    {
        StudentNote::create(['student_id' => $this->student->id, 'supervisor_id' => $this->supervisor->id, 'type' => 'health', 'title' => 'داخلي', 'content' => 'سري', 'visibility' => 'internal']);
        StudentNote::create(['student_id' => $this->student->id, 'supervisor_id' => $this->supervisor->id, 'type' => 'general', 'title' => 'متاح', 'content' => 'متابعة', 'visibility' => 'guardian']);
        $this->actingAs($this->parent)->get('/parent/student-followup?student='.$this->student->id)->assertOk()->assertSee('متاح')->assertDontSee('داخلي');
    }

    public function test_monthly_report_scopes_counts_to_supervised_classrooms(): void
    {
        $second = Classroom::create(['name' => 'الثاني', 'stage' => 'أساسي', 'section' => 'ب', 'academic_year_id' => $this->classroom->academic_year_id]);
        $this->supervisor->supervisedClassrooms()->attach($second);
        $secondStudent = Student::create(['user_id' => User::factory()->create(['role' => 'student'])->id, 'student_number' => 'S-2', 'classroom_id' => $second->id, 'status' => 'active']);
        $outside = Classroom::create(['name' => 'الثالث', 'stage' => 'أساسي', 'section' => 'ج', 'academic_year_id' => $this->classroom->academic_year_id]);
        $outsideStudent = Student::create(['user_id' => User::factory()->create(['role' => 'student'])->id, 'student_number' => 'S-3', 'classroom_id' => $outside->id, 'status' => 'active']);
        foreach ([[$this->student, $this->classroom, 'present'], [$secondStudent, $second, 'absent'], [$outsideStudent, $outside, 'absent']] as [$student,$classroom,$status]) {
            Attendance::create(['student_id' => $student->id, 'classroom_id' => $classroom->id, 'date' => '2026-09-30', 'status' => $status, 'recorded_by' => $this->supervisor->id]);
        }
        $this->actingAs($this->supervisor)->get('/supervisor/reports?period=monthly&month=2026-09')->assertOk()
            ->assertViewHas('summary', fn ($summary) => $summary['total'] === 2 && $summary['present'] === 1 && $summary['absent'] === 1)
            ->assertViewHas('students', fn ($students) => $students->count() === 2);
        $this->get('/supervisor/reports?period=monthly&month=2026-09&classroom_id='.$second->id)->assertOk()
            ->assertViewHas('summary', fn ($summary) => $summary['total'] === 1 && $summary['absent'] === 1);
        $this->get('/supervisor/reports?classroom_id='.$outside->id)->assertForbidden();
        $this->get('/supervisor/reports?student_id='.$outsideStudent->id)->assertForbidden();
    }

    public function test_daily_and_custom_reports_use_selected_dates(): void
    {
        foreach (['2026-09-29' => 'absent', '2026-09-30' => 'present'] as $date => $status) {
            Attendance::create(['student_id' => $this->student->id, 'classroom_id' => $this->classroom->id, 'date' => $date, 'status' => $status, 'recorded_by' => $this->supervisor->id]);
        }
        $this->actingAs($this->supervisor)->get('/supervisor/reports?period=daily&date=2026-09-29&classroom_id='.$this->classroom->id)->assertOk()
            ->assertViewHas('summary', fn ($summary) => $summary['total'] === 1 && $summary['absent'] === 1);
        $this->get('/supervisor/reports?period=custom&date_from=2026-09-30&date_to=2026-09-29')->assertOk()
            ->assertViewHas('summary', fn ($summary) => $summary['total'] === 2 && $summary['rate'] === 50.0);
    }

    public function test_attendance_page_accepts_a_selected_date(): void
    {
        $this->actingAs($this->supervisor)->get('/supervisor/attendance?date=2026-09-29')->assertOk()
            ->assertViewHas('date', '2026-09-29');
    }

    public function test_attendance_history_renders_when_a_record_student_is_unavailable(): void
    {
        Attendance::create(['student_id' => $this->student->id, 'classroom_id' => $this->classroom->id, 'date' => '2026-09-29', 'status' => 'present', 'recorded_by' => $this->supervisor->id]);
        $response = $this->actingAs($this->supervisor)->get('/supervisor/reports?date=2026-09-29')->assertOk();
        $data = $response->original->getData();
        $data['records']->first()->setRelation('student', null);
        $this->assertStringContainsString('طالب غير متاح', view('supervisor.attendance.history', $data)->render());
    }

    public function test_existing_scan_endpoint_still_records_presence_and_enforces_supervision(): void
    {
        $payload = ['student_id' => $this->student->id, 'classroom_id' => $this->classroom->id, 'date' => '2026-09-30'];
        $this->actingAs($this->supervisor)->postJson('/supervisor/attendance/scan', $payload)->assertOk()->assertJsonPath('ok', true);
        $this->assertTrue(Attendance::where('student_id', $this->student->id)->whereDate('date', '2026-09-30')->where('status', 'present')->exists());
        $this->actingAs($this->teacher)->postJson('/supervisor/attendance/scan', $payload)->assertForbidden();
        $otherSupervisor = User::factory()->create(['role' => 'supervisor', 'status' => 'active']);
        $this->actingAs($otherSupervisor)->postJson('/supervisor/attendance/scan', $payload)->assertForbidden();
    }
}
