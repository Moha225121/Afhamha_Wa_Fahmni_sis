<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\ParentNotificationService;
use App\Services\ResultPublicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class AutomaticStudentNotificationTest extends TestCase
{
    use CreatesStudentEducationFixtures, RefreshDatabase, \Tests\Concerns\SubmitsTeacherCreationForms;

    public function test_available_assignment_is_sent_to_active_students_once_and_draft_waits_for_publication(): void
    {
        $ctx = $this->createStudentFixture('AUTO-ASSIGNMENT');
        $inactiveUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Student::create([
            'user_id' => $inactiveUser->id,
            'student_number' => 'ST-AUTO-INACTIVE',
            'classroom_id' => $ctx['classroom']->id,
            'status' => 'inactive',
        ]);
        $outside = $this->createStudentFixture('AUTO-OUTSIDE');
        $service = app(ParentNotificationService::class);
        $payload = ['title' => 'واجب جديد', 'body' => 'تم نشر واجب الرياضيات', 'category' => 'assignment'];

        $draft = Assignment::create([
            'title' => 'واجب مسودة',
            'classroom_id' => $ctx['classroom']->id,
            'subject_id' => $this->createSubjectFor($ctx['classroom'], 'AUTO-DRAFT')->id,
            'teacher_id' => Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher'])->id, 'status' => 'active'])->id,
            'due_at' => now()->addDay(),
            'due_date' => now()->addDay()->toDateString(),
            'max_score' => 10,
            'status' => 'draft',
        ]);
        $this->assertSame(0, $ctx['user']->notifications()->count());

        $draft->update(['status' => 'active', 'published_at' => now()]);
        $eventKey = 'assignment:'.$draft->id.':available';
        $this->assertSame(1, $service->sendToStudents([$ctx['classroom']->id], $payload, $eventKey, route('student.assignments.show', $draft)));
        $this->assertSame(1, $service->sendToStudents([$ctx['classroom']->id], $payload, $eventKey, route('student.assignments.show', $draft)));

        $this->assertSame(1, $ctx['user']->notifications()->count());
        $this->assertSame(0, $inactiveUser->notifications()->count());
        $this->assertSame(0, $outside['user']->notifications()->count());
    }

    public function test_teacher_assignment_creation_notifies_students_and_unrelated_edits_do_not_duplicate(): void
    {
        [$teacherUser, $classroom, $subject, $studentUser] = $this->teacherAcademicFixture('AUTO-ASSIGNMENT-CREATE');
        $payload = [
            'title' => 'واجب منشور للطلاب',
            'classroom_ids' => [$classroom->id],
            'subject_id' => $subject->id,
            'due_date' => now()->addDays(3)->toDateString(),
            'max_score' => 20,
            'description' => 'حل التدريبات المطلوبة.',
        ];

        $this->actingAs($teacherUser)->postTeacherCreation(route('teacher.assignments.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $assignment = Assignment::where('title', $payload['title'])->firstOrFail();
        $this->assertSame(1, $studentUser->notifications()->count());
        $this->assertSame($assignment->id, $studentUser->notifications()->sole()->data['assignment_id']);

        $this->actingAs($teacherUser)->put(route('teacher.assignments.update', $assignment), $payload + ['title' => 'واجب محدث'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $studentUser->notifications()->count());
    }

    public function test_unassigned_teacher_cannot_trigger_student_assignment_notifications(): void
    {
        [$teacherUser, $classroom, $subject, $studentUser] = $this->teacherAcademicFixture('AUTO-ASSIGNMENT-SCOPE');
        $otherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Teacher::create(['user_id' => $otherUser->id, 'status' => 'active']);

        $this->actingAs($otherUser)->postTeacherCreation(route('teacher.assignments.store'), [
            'title' => 'محاولة خارج النطاق',
            'classroom_ids' => [$classroom->id],
            'subject_id' => $subject->id,
            'due_date' => now()->addDays(2)->toDateString(),
            'max_score' => 10,
        ])->assertForbidden();
        $this->assertSame(0, $studentUser->notifications()->count());
    }

    public function test_exam_draft_is_silent_then_first_available_status_notifies_once(): void
    {
        [$teacherUser, $classroom, $subject, $studentUser] = $this->teacherAcademicFixture('AUTO-EXAM');
        $payload = [
            'title' => 'اختبار متاح',
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'starts_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'duration_minutes' => 30,
            'status' => 'draft',
            'questions' => [['type' => 'short_answer', 'text' => 'اذكر تعريفًا مختصرًا.', 'score' => 5]],
        ];

        $this->actingAs($teacherUser)->postTeacherCreation(route('teacher.exams.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $exam = DB::table('exams')->where('title', 'اختبار متاح')->firstOrFail();
        $this->assertSame(0, $studentUser->notifications()->count());

        $this->actingAs($teacherUser)->patch(route('teacher.exams.status', $exam->id), ['status' => 'scheduled'])->assertRedirect();
        $this->assertSame(1, $studentUser->notifications()->count());
        $this->assertSame($exam->id, $studentUser->notifications()->sole()->data['exam_id']);
        $this->actingAs($teacherUser)->patch(route('teacher.exams.status', $exam->id), ['status' => 'scheduled'])->assertRedirect();
        $this->assertSame(1, $studentUser->notifications()->count());
        $this->actingAs($teacherUser)->patch(route('teacher.exams.status', $exam->id), ['status' => 'published'])->assertRedirect();
        $this->assertSame(1, $studentUser->notifications()->count());
    }

    public function test_results_publish_only_marked_students_and_viewing_does_not_create_a_new_notification(): void
    {
        $admin = $this->createAdmin();
        $year = AcademicYear::create(['name' => '2026/2027-AUTO-RESULTS', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $classroom = Classroom::create(['name' => 'نتائج تلقائية', 'stage' => 'أساسي', 'section' => 'أ', 'academic_year_id' => $year->id]);
        $subject = $this->createSubjectFor($classroom, 'AUTO-RESULTS');
        $marked = $this->createStudentFixture('AUTO-MARKED');
        $unmarkedUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $unmarked = Student::create(['user_id' => $unmarkedUser->id, 'student_number' => 'ST-AUTO-UNMARKED', 'classroom_id' => $classroom->id, 'status' => 'active']);
        $marked['student']->update(['classroom_id' => $classroom->id]);
        DB::table('exam_periods')->insertGetId([
            'name' => 'نتائج آلية', 'academic_year_id' => $year->id, 'term' => 'الأول', 'exam_type' => 'نهائي',
            'classroom_id' => $classroom->id, 'status' => 'draft', 'created_by' => $admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $periodId = (int) DB::table('exam_periods')->max('id');
        DB::table('result_marks')->insert([
            'exam_period_id' => $periodId, 'student_id' => $marked['student']->id, 'subject_id' => $subject->id,
            'score' => 88, 'maximum_score' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $results = app(ResultPublicationService::class);
        $results->transition($periodId, 'under_review');
        $results->transition($periodId, 'approved');
        $results->transition($periodId, 'published');

        $this->assertSame(1, $marked['user']->notifications()->count());
        $this->assertSame(0, $unmarkedUser->notifications()->count());
        $this->actingAs($marked['user'])->get(route('student.results'))->assertOk();
        $record = $marked['user']->notifications()->sole();
        $this->patchJson(route('student.notifications.read', $record))->assertJsonPath('url', route('student.results'));
        $this->assertSame(1, $marked['user']->notifications()->count());
        $this->assertDatabaseHas('result_publications', ['exam_period_id' => $periodId, 'student_id' => $marked['student']->id]);
        $this->assertDatabaseMissing('result_publications', ['exam_period_id' => $periodId, 'student_id' => $unmarked->id]);
    }

    /** @return array{0: User, 1: Classroom, 2: Subject, 3: User} */
    private function teacherAcademicFixture(string $suffix): array
    {
        $year = AcademicYear::create(['name' => '2026/2027-'.$suffix, 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $classroom = Classroom::create(['name' => 'صف '.$suffix, 'stage' => 'أساسي', 'section' => 'أ', 'academic_year_id' => $year->id]);
        $subject = $this->createSubjectFor($classroom, $suffix);
        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'status' => 'active']);
        DB::table('teacher_assignments')->insert(['teacher_id' => $teacher->id, 'classroom_id' => $classroom->id, 'subject_id' => $subject->id]);
        $studentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Student::create(['user_id' => $studentUser->id, 'student_number' => 'ST-'.$suffix, 'classroom_id' => $classroom->id, 'status' => 'active']);

        return [$teacherUser, $classroom, $subject, $studentUser];
    }
}
