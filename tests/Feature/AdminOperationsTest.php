<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Classroom $classroom;

    private Teacher $teacher;

    private Subject $subject;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30', 'is_current' => true]);
        $this->classroom = Classroom::create(['name' => 'الأول', 'stage' => 'أساسي', 'section' => 'أ', 'academic_year_id' => $year->id]);
        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $this->teacher = Teacher::create(['user_id' => $teacherUser->id, 'status' => 'active']);
        $this->subject = Subject::create(['name' => 'رياضيات', 'code' => 'MATH-1', 'stage' => 'أساسي', 'status' => 'active']);
        $studentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->student = Student::create(['user_id' => $studentUser->id, 'student_number' => 'S1', 'classroom_id' => $this->classroom->id, 'status' => 'active']);
    }

    public function test_schedule_conflicts_are_rejected(): void
    {
        $payload = ['classroom_id' => $this->classroom->id, 'teacher_id' => $this->teacher->id, 'subject_id' => $this->subject->id, 'day_of_week' => 0, 'starts_at' => '08:00', 'ends_at' => '09:00'];
        $this->actingAs($this->admin)->post('/admin/schedules', $payload)->assertRedirect('/admin/schedules');
        $this->actingAs($this->admin)->from('/admin/schedules/create')->post('/admin/schedules', $payload + ['starts_at' => '08:30', 'ends_at' => '09:30'])->assertSessionHasErrors('starts_at');
        $this->assertDatabaseCount('schedules', 1);
    }

    public function test_admin_can_record_attendance_and_publish_grade(): void
    {
        $this->actingAs($this->admin)->post('/admin/attendance', ['date' => '2026-08-23', 'records' => [$this->student->id => 'present']])->assertSessionHasNoErrors();
        $exam = DB::table('exams')->insertGetId(['title' => 'اختبار', 'subject_id' => $this->subject->id, 'classroom_id' => $this->classroom->id, 'teacher_id' => $this->teacher->id, 'starts_at' => now(), 'duration_minutes' => 30, 'total_score' => 20, 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->admin)->post('/admin/grades', ['exam_id' => $exam, 'scores' => [$this->student->id => 18]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('attendance_records', ['student_id' => $this->student->id, 'status' => 'present']);
        $this->assertDatabaseHas('grades', ['student_id' => $this->student->id, 'score' => 18]);
    }

    public function test_library_upload_uses_storage(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin)->post('/admin/library', ['title' => 'كتاب', 'file' => UploadedFile::fake()->create('book.pdf', 100, 'application/pdf'), 'is_public' => 1])->assertSessionHasNoErrors();
        $row = DB::table('library_resources')->first();
        Storage::disk('public')->assertExists($row->file_path);
    }
}
