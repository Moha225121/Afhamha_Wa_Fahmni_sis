<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_filter_data_and_use_only_published_normalized_results(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11 12:00:00'));
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $classroom = Classroom::create(['name' => 'Grade 6', 'section' => 'A', 'stage' => 'Basic', 'academic_year_id' => $year->id]);
        $otherClassroom = Classroom::create(['name' => 'Grade 7', 'section' => 'A', 'stage' => 'Basic', 'academic_year_id' => $year->id]);
        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'status' => 'active']);
        $studentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $student = Student::create(['user_id' => $studentUser->id, 'student_number' => 'S-REPORT', 'classroom_id' => $classroom->id, 'status' => 'active']);
        $otherUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Student::create(['user_id' => $otherUser->id, 'student_number' => 'S-REPORT-OTHER', 'classroom_id' => $otherClassroom->id, 'status' => 'active']);
        $subject = Subject::create(['name' => 'Math', 'code' => 'MATH-REPORT', 'stage' => 'Basic', 'status' => 'active']);

        $periodId = DB::table('exam_periods')->insertGetId([
            'name' => 'Published Term', 'academic_year_id' => $year->id, 'term' => 'Term 1', 'exam_type' => 'Term',
            'status' => 'published', 'published_at' => now()->subDays(3), 'created_by' => $admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('result_publications')->insert([
            'exam_period_id' => $periodId,
            'student_id' => $student->id,
            'payload' => json_encode(['subjects' => [['subject_id' => $subject->id, 'subject' => 'Math', 'score' => 8, 'maximum_score' => 10]]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $publishedExam = DB::table('exams')->insertGetId([
            'title' => 'Legacy Published', 'subject_id' => $subject->id, 'classroom_id' => $classroom->id,
            'teacher_id' => $teacher->id, 'starts_at' => now()->subDays(2), 'duration_minutes' => 30,
            'total_score' => 20, 'status' => 'published', 'legacy_results_published' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $draftExam = DB::table('exams')->insertGetId([
            'title' => 'Unpublished Draft Result', 'subject_id' => $subject->id, 'classroom_id' => $classroom->id,
            'teacher_id' => $teacher->id, 'starts_at' => now()->subDay(), 'duration_minutes' => 30,
            'total_score' => 20, 'status' => 'draft', 'legacy_results_published' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('grades')->insert([
            ['exam_id' => $publishedExam, 'student_id' => $student->id, 'score' => 18, 'published_at' => now()->subDays(2), 'created_at' => now(), 'updated_at' => now()],
            ['exam_id' => $draftExam, 'student_id' => $student->id, 'score' => 20, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        $automaticExam = DB::table('exams')->insertGetId([
            'title' => 'Legacy Automatic Published', 'subject_id' => $subject->id, 'classroom_id' => $classroom->id,
            'teacher_id' => $teacher->id, 'starts_at' => now()->subDays(2), 'duration_minutes' => 30,
            'total_score' => 10, 'status' => 'published', 'legacy_results_published' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('exam_attempts')->insert([
            ['exam_id' => $automaticExam, 'student_id' => $student->id, 'attempt_number' => 1, 'started_at' => now()->subDays(2), 'expires_at' => now()->subDays(2)->addMinutes(30), 'submitted_at' => now()->subDays(2)->addMinutes(20), 'graded_at' => now()->subDays(2)->addMinutes(20), 'status' => 'submitted', 'score' => 1, 'maximum_score' => 10, 'percentage' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['exam_id' => $automaticExam, 'student_id' => $student->id, 'attempt_number' => 2, 'started_at' => now()->subDay(), 'expires_at' => now()->subDay()->addMinutes(30), 'submitted_at' => now()->subDay()->addMinutes(20), 'graded_at' => now()->subDay()->addMinutes(20), 'status' => 'submitted', 'score' => 6, 'maximum_score' => 10, 'percentage' => 60, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('attendance_records')->insert([
            ['student_id' => $student->id, 'classroom_id' => $classroom->id, 'date' => now()->subDay()->toDateString(), 'status' => 'present', 'recorded_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()],
            ['student_id' => $student->id, 'classroom_id' => $classroom->id, 'date' => now()->subDays(20)->toDateString(), 'status' => 'absent', 'recorded_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $scheduledExam = DB::table('exams')->insertGetId([
            'title' => 'Scheduled Exam for Admin', 'subject_id' => $subject->id, 'classroom_id' => $classroom->id,
            'teacher_id' => $teacher->id, 'starts_at' => now()->addHour(), 'duration_minutes' => 30,
            'total_score' => 20, 'status' => 'scheduled', 'legacy_results_published' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('admin.reports.index', [
            'from' => now()->subDays(5)->toDateString(),
            'to' => now()->toDateString(),
            'classroom_id' => $classroom->id,
        ]))->assertOk()
            ->assertViewHas('students', fn ($rows) => $rows->sum('total') === 1)
            ->assertViewHas('attendance', fn ($rows) => $rows->sum('total') === 1 && $rows->first()->status === 'present')
            ->assertViewHas('results', fn ($results) => $results->total === 3 && $results->average === 76.67 && $results->highest === 90.0 && $results->lowest === 60.0)
            ->assertSeeText('Scheduled Exam for Admin')
            ->assertDontSeeText('Unpublished Draft Result')
            ->assertViewHas('exams', fn ($exams) => $exams->count() === 1 && $exams->first()->id === $scheduledExam && $exams->first()->status === 'scheduled');

        $this->get(route('admin.reports.index', ['exam_status' => 'published']))
            ->assertOk()
            ->assertSeeText('Legacy Published')
            ->assertDontSeeText('Scheduled Exam for Admin')
            ->assertDontSeeText('Unpublished Draft Result');

        $this->get(route('admin.reports.pdf', [
            'from' => now()->subDays(5)->toDateString(),
            'to' => now()->toDateString(),
            'classroom_id' => $classroom->id,
        ]))->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertSee('%PDF-', false);
    }
}
